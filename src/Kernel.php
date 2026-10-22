<?php

declare(strict_types=1);

namespace MailboxMcp;

use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Audit\Logger;
use MailboxMcp\Http\Request;
use MailboxMcp\Http\Response;
use MailboxMcp\Mail\MailSessionFactoryInterface;
use MailboxMcp\Mail\Send\DailyCap;
use MailboxMcp\Protocol\Dispatcher;
use MailboxMcp\Protocol\Era;
use MailboxMcp\Protocol\JsonRpc;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\Message;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Protocol\ServerInfo;
use MailboxMcp\Security\BearerTokenAuthenticator;
use MailboxMcp\Security\Lockout;
use MailboxMcp\Security\OriginGuard;
use MailboxMcp\Security\RateLimiter;
use MailboxMcp\Support\Clock;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\ToolInterface;
use Throwable;

/**
 * The single HTTP entry point: turns a {@see Request} into a
 * {@see Response}, running every security guard (HTTPS, routing,
 * method, lockout, rate limit, origin, auth, body size, content type)
 * before handing a parsed JSON-RPC message to the {@see Dispatcher}, and
 * writing an audit log entry for everything worth recording along the
 * way.
 *
 * Lockout and the rate limit run *before* the origin check (and auth)
 * specifically so an unauthenticated, no-token caller cannot grow the
 * audit log without bound: a locked-out IP is turned away silently (no
 * audit write at all), and a caller stuck above the rate limit is only
 * audited once per window (see {@see RateLimitResult::$firstDenial}), so
 * a bad `Origin` alone can produce at most one `origin_rejected` line per
 * rate-limit window per IP.
 */
final class Kernel
{
    private const PATH_TOKEN_PATTERN = '#^/t/([A-Za-z0-9_-]+)/mcp$#';

    /** Legacy, implementation-defined JSON-RPC error range; used for rate limiting. */
    private const RATE_LIMIT_ERROR_CODE = -32000;

    private readonly Registry $registry;

    private readonly Dispatcher $dispatcher;

    private readonly BearerTokenAuthenticator $authenticator;

    private readonly OriginGuard $origin;

    private readonly RateLimiter $rateLimiter;

    private readonly Lockout $lockout;

    private readonly Logger $audit;

    private readonly int $maxBodyBytes;

    private readonly int $requestBudgetSeconds;

    private readonly bool $allowHttp;

    private readonly DailyCap $dailyCap;

    private readonly int $retentionDays;

    /**
     * @param array<string, mixed>      $config validated top-level config (see Config\Validator)
     * @param array<int, ToolInterface> $tools  in deterministic (constructor) order
     */
    public function __construct(
        array $config,
        private readonly Clock $clock,
        MailSessionFactoryInterface $sessions,
        array $tools,
        ?Logger $audit = null,
        private readonly int $gcProbabilityPercent = 1,
    ) {
        /** @var array<string, mixed> $auth */
        $auth = $config['auth'];
        /** @var array<string, mixed> $security */
        $security = $config['security'];
        /** @var array<string, mixed> $limits */
        $limits = $config['limits'];
        /** @var array<string, mixed> $paths */
        $paths = $config['paths'];
        /** @var array<string, mixed> $auditCfg */
        $auditCfg = $config['audit'];
        /** @var array<string, mixed> $server */
        $server = $config['server'];

        $var = (string) $paths['var'];

        $accounts = AccountResolver::fromConfig($config);
        $this->registry = new Registry($tools, $accounts, $sessions, $limits, $clock);
        $this->dispatcher = new Dispatcher(new ServerInfo((string) $server['instructions_extra']), $this->registry);

        $this->authenticator = new BearerTokenAuthenticator(
            (string) $auth['token'],
            (bool) $auth['allow_url_token'],
        );

        /** @var list<string> $allowedOrigins */
        $allowedOrigins = $security['allowed_origins'];
        $this->origin = new OriginGuard($allowedOrigins);

        $this->rateLimiter = new RateLimiter(
            $var . '/ratelimit',
            $clock,
            (int) $security['rate_limit_per_minute'],
            (int) $security['rate_limit_global_per_minute'],
        );

        $this->lockout = new Lockout(
            $var . '/lockout',
            $clock,
            (int) $security['lockout_failures'],
            (int) $security['lockout_window_minutes'],
            (int) $security['lockout_minutes'],
        );

        $this->audit = $audit ?? new Logger(
            $var . '/audit',
            $clock,
            (int) $auditCfg['retention_days'],
            (bool) $auditCfg['hash_ip'],
            (bool) $auditCfg['enabled'],
        );

        // Same location public/index.php hands to SendDraftTool; the cap
        // value is irrelevant here, this instance is only used for gc().
        $this->dailyCap = new DailyCap($var . '/state', $clock, 0);
        $this->retentionDays = (int) $auditCfg['retention_days'];

        $this->maxBodyBytes = (int) $security['max_body_bytes'];
        $this->requestBudgetSeconds = (int) $limits['request_budget_seconds'];
        $this->allowHttp = (bool) $security['allow_http'];
    }

    public function handle(Request $request): Response
    {
        // Two budgets: set_time_limit() bounds CPU-ish script time (it does
        // not count blocking stream I/O on Linux), the wall-clock deadline
        // is enforced by the IMAP/SMTP clients on every command and wait.
        if (function_exists('set_time_limit')) {
            set_time_limit($this->requestBudgetSeconds);
        }

        $this->registry->setDeadline(hrtime(true) + $this->requestBudgetSeconds * 1e9);

        // (1) HTTPS required unless explicitly relaxed for local testing.
        if (!$request->isHttps && !$this->allowHttp) {
            return Response::notFound();
        }

        // (2) Routing: /mcp (no path token) or /t/<token>/mcp (path token).
        $pathToken = null;

        if ('/mcp' === $request->path) {
            $pathToken = null;
        } elseif (1 === preg_match(self::PATH_TOKEN_PATTERN, $request->path, $matches)) {
            $pathToken = $matches[1];
        } else {
            return Response::notFound();
        }

        // (3) Method.
        if ('POST' !== $request->method) {
            return Response::empty(405, ['Allow' => 'POST']);
        }

        $ip = $request->clientIp;

        // (4) Lockout. No audit entry here: a locked-out IP is turned away
        // silently; the auth_failed entry that triggered the lock (step 7,
        // on an earlier request) already recorded it via `locked: true`.
        if ($this->lockout->isLocked($ip)) {
            return Response::notFound();
        }

        // (5) Rate limit. Only the hit that first crosses the limit in
        // this window is audited, so a caller stuck above the limit for
        // the rest of the window cannot grow the audit log without bound.
        $rate = $this->rateLimiter->hit($ip);

        if (!$rate->allowed) {
            if ($rate->firstDenial) {
                $this->audit->log(['event' => 'rate_limited', 'ip' => $ip]);
            }

            return Response::json(
                429,
                JsonRpc::error(null, self::RATE_LIMIT_ERROR_CODE, 'Rate limit exceeded'),
                ['Retry-After' => (string) $rate->retryAfterSeconds],
            );
        }

        // (6) Origin. Gated behind the rate limit above, so a caller with
        // no valid token and a bad Origin is capped at the same per-IP /
        // global budget as everyone else instead of writing unboundedly
        // many origin_rejected lines.
        if (!$this->origin->allows($request->header('origin'))) {
            $this->audit->log(['event' => 'origin_rejected', 'ip' => $ip]);

            return Response::json(403, JsonRpc::error(null, McpError::INVALID_REQUEST, 'Origin not allowed'));
        }

        // (7) Authentication. No state file is touched above this point
        // for an unauthenticated caller beyond what steps 4-6 already do,
        // so opportunistic GC only ever runs from here on.
        $auth = $this->authenticator->authenticate($request, $pathToken);

        if (!$auth->ok) {
            $this->lockout->recordFailure($ip);
            $locked = $this->lockout->isLocked($ip);
            $this->audit->log(['event' => 'auth_failed', 'ip' => $ip, 'reason' => $auth->reason, 'locked' => $locked]);
            $this->maybeGc();

            return Response::notFound();
        }

        $this->lockout->clear($ip);

        // (8) Body size.
        if (strlen($request->body) > $this->maxBodyBytes) {
            $response = Response::json(413, JsonRpc::error(null, McpError::INVALID_REQUEST, 'Request body too large'));
            $this->maybeGc();

            return $response;
        }

        // (9) Content type.
        $contentType = $request->header('content-type');

        if (null === $contentType || !self::isJsonContentType($contentType)) {
            $response = Response::empty(415);
            $this->maybeGc();

            return $response;
        }

        // (10) Parse + dispatch.
        $response = $this->dispatchBody($request);
        $this->maybeGc();

        return $response;
    }

    private function dispatchBody(Request $request): Response
    {
        $id = null;

        try {
            $message = JsonRpc::parse($request->body);
            $id = $message->id;

            $start = hrtime(true);
            $response = $this->dispatcher->dispatch($request, $message);
            $durationMs = (int) intdiv(hrtime(true) - $start, 1_000_000);

            $this->auditToolCall($request, $message, $response, $durationMs);

            return $response;
        } catch (ProtocolException $e) {
            return Response::json($e->httpStatus(), $e->toJsonRpc(null));
        } catch (Throwable $e) {
            $this->audit->log([
                'event' => 'internal_error',
                'ip' => $request->clientIp,
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);

            return Response::json(500, JsonRpc::error($id, McpError::INTERNAL, 'Internal error'));
        }
    }

    private function auditToolCall(Request $request, Message $message, Response $response, int $durationMs): void
    {
        if ('tools/call' !== $message->method) {
            return;
        }

        $name = $message->params['name'] ?? null;
        $rawArguments = $message->params['arguments'] ?? [];
        $arguments = is_array($rawArguments) ? $rawArguments : [];

        $entry = [
            'event' => 'tool_call',
            'ip' => $request->clientIp,
            'era' => Era::detect($message->params)->value,
            'method' => $message->method,
            'tool' => is_string($name) ? $name : null,
            'account' => $this->registry->lastAccountId,
            'args' => self::summarizeArgs($arguments),
            'ok' => $this->isToolCallOk($response),
            'duration_ms' => $durationMs,
        ];

        foreach (self::auditExtras($this->registry->lastAudit) as $key => $value) {
            $entry[$key] = $value;
        }

        if (null !== $this->registry->lastError) {
            $entry['error'] = $this->registry->lastError::class . ': ' . $this->registry->lastError->getMessage();
        }

        $this->audit->log($entry);
    }

    /**
     * The only tool-supplied audit fields ever copied into a `tool_call`
     * entry (see {@see \MailboxMcp\Tools\ToolResult::withAudit()}):
     * `recipients` (list of strings), `message_id` (string) and `sent`
     * (bool). Anything else, or a value of the wrong type, is dropped.
     *
     * @param array<string, mixed> $audit
     *
     * @return array<string, mixed>
     */
    private static function auditExtras(array $audit): array
    {
        $extras = [];

        if (isset($audit['recipients']) && is_array($audit['recipients'])) {
            $extras['recipients'] = array_values(array_filter($audit['recipients'], 'is_string'));
        }

        if (isset($audit['message_id']) && is_string($audit['message_id'])) {
            $extras['message_id'] = $audit['message_id'];
        }

        if (isset($audit['sent']) && is_bool($audit['sent'])) {
            $extras['sent'] = $audit['sent'];
        }

        return $extras;
    }

    private function isToolCallOk(Response $response): bool
    {
        if (200 !== $response->status) {
            return false;
        }

        $result = $this->dispatcher->lastResult;

        if (null === $result) {
            return false;
        }

        return false === $result['isError'];
    }

    /**
     * Only the fixed, non-content keys below are ever copied into the
     * audit log; anything else (message text, recipients, …) is dropped
     * so the audit log never leaks mail content. `uids`, `to` and `cc`
     * are reduced to a count.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function summarizeArgs(array $args): array
    {
        $summary = [];

        foreach ([
            'folder',
            'uid',
            'limit',
            'max_chars',
            'draft_uid',
            'in_reply_to_uid',
            'processed',
            'unseen_only',
            'exclude_processed',
            'html',
        ] as $key) {
            if (array_key_exists($key, $args)) {
                $summary[$key] = $args[$key];
            }
        }

        foreach (['uids' => 'uids_count', 'to' => 'to_count', 'cc' => 'cc_count'] as $key => $countKey) {
            if (isset($args[$key]) && is_array($args[$key])) {
                $summary[$countKey] = count($args[$key]);
            }
        }

        return $summary;
    }

    private static function isJsonContentType(string $contentType): bool
    {
        $main = strtolower(trim(explode(';', $contentType, 2)[0]));

        return str_starts_with($main, 'application/json');
    }

    /**
     * With probability {@see self::$gcProbabilityPercent} % (1 by default;
     * the constructor knob exists for tests), removes stale state files:
     * expired lockouts, old audit logs, finished rate-limit windows and
     * send counters older than `audit.retention_days`.
     */
    private function maybeGc(): void
    {
        if ($this->gcProbabilityPercent <= 0 || random_int(1, 100) > $this->gcProbabilityPercent) {
            return;
        }

        // Housekeeping must never turn a served response into an error.
        try {
            $this->lockout->gc();
            $this->audit->gc();
            $this->rateLimiter->gc();
            $this->dailyCap->gc($this->retentionDays);
        } catch (Throwable $e) {
            error_log('mailbox-mcp: state gc error: ' . $e->getMessage());
        }
    }
}

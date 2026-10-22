<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

use MailboxMcp\Http\Request;

interface Authenticator
{
    /**
     * @param ?string $pathToken token supplied via the URL path, if any
     */
    public function authenticate(Request $request, ?string $pathToken): AuthResult;
}

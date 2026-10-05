<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** 业务规则违反，一律以 422 返回给调用方 */
class DomainException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}

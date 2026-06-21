<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Hyperf\Validation\ValidationException;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ValidationExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class ValidationExceptionHandler extends \Hyperf\Validation\ValidationExceptionHandler
{
    use HttpErrorResponder;

    /**
     * Handle the exception, and return the specified result.
     * @param ValidationException $throwable
     */
    #[ExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();
        /** @var ValidationException $throwable */
        $message = $throwable->validator->errors()
                                        ->first();
        return $this->respond($response, $message, RcodeConf::ERRNO_PARAMS);
    }

    /**
     * Determine if the current exception handler should handle the exception,.
     *
     * @return bool
     *              If return true, then this exception handler will handle the exception,
     *              If return false, then delegate to next handler
     */
    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}

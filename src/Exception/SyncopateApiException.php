<?php

namespace Philharmonic\SyncopateBundle\Exception;

class SyncopateApiException extends \RuntimeException
{
    private ?array $apiResponse = null;

    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $apiResponse = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->apiResponse = $apiResponse;
    }

    public function getApiResponse(): ?array
    {
        return $this->apiResponse;
    }
}
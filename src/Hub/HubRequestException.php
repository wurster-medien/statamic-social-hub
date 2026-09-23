<?php

namespace WursterMedien\SocialHub\Hub;

/**
 * Der Hub hat mit einem Fehlerstatus (4xx/5xx) geantwortet.
 */
class HubRequestException extends HubException
{
    /**
     * @param  array<string, list<string>>  $errors  Validierungsfehler (bei 422)
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly ?string $hubMessage = null,
    ) {
        parent::__construct($message, $status);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function isValidationError(): bool
    {
        return $this->status === 422;
    }

    /**
     * Alle Validierungsfehler als flache Liste.
     *
     * @return list<string>
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->errors as $field => $fieldErrors) {
            foreach ((array) $fieldErrors as $error) {
                $messages[] = is_string($field) ? "{$field}: {$error}" : (string) $error;
            }
        }

        return $messages;
    }
}

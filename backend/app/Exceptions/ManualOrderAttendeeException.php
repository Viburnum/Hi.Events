<?php

namespace HiEvents\Exceptions;

use Exception;

class ManualOrderAttendeeException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $attendeeIndex,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public function getAttendeeIndex(): int
    {
        return $this->attendeeIndex;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getValidationKey(): string
    {
        return sprintf('attendees.%d.%s', $this->attendeeIndex, $this->field);
    }
}

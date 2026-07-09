<?php

namespace Modules\Nursing\Exceptions;

use RuntimeException;

class AssignmentValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(private readonly array $reasons)
    {
        parent::__construct(implode('; ', $reasons));
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}

<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

/**
 * The provider could not answer (outage, rate limit, bad key, bad request,
 * timeout). The agent moves on to the next provider. The message is for logs
 * only and never reaches the customer.
 */
final class LlmUnavailableException extends \RuntimeException
{
}

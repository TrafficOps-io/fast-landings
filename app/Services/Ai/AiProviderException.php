<?php

namespace App\Services\Ai;

use RuntimeException;

/** Messages are safe to show to users; never attach provider responses or previous exceptions. */
class AiProviderException extends RuntimeException {}

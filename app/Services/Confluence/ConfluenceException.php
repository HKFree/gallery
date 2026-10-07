<?php

namespace App\Services\Confluence;

use RuntimeException;

/**
 * A Confluence page could not be resolved or read; the message is shown to the user.
 */
class ConfluenceException extends RuntimeException {}

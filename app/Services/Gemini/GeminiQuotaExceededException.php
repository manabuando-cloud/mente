<?php

namespace App\Services\Gemini;

/** 429: クォータ超過。バッチ処理はこれを受けたら即中断する。 */
class GeminiQuotaExceededException extends GeminiException {}

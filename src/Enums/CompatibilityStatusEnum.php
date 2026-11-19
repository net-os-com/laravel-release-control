<?php

namespace NetOs\ReleaseControl\Enums;

enum CompatibilityStatusEnum: string
{
    case COMPATIBLE = 'compatible';
    case APP_UPDATE_REQUIRED = 'app_update_required';
    case API_UPDATE_PENDING = 'api_update_pending';
    case INVALID_CLIENT_METADATA = 'invalid_client_metadata';
    case LEGACY = 'legacy';
    case UNKNOWN = 'unknown';
}

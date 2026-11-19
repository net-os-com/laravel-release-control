<?php

namespace NetOs\ReleaseControl\Enums;

enum ClientPlatformEnum: string
{
    case IOS = 'ios';
    case ANDROID = 'android';
    case WEB = 'web';
}

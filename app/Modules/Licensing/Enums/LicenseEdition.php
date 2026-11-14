<?php

namespace App\Modules\Licensing\Enums;

/**
 * The editions in licenses.edition, plus Development: a license signed with the
 * development key for dev and test machines. It is never issued by the license
 * server, and release images do not trust the development key.
 */
enum LicenseEdition: string
{
    case HostedSaas = 'hosted_saas';
    case SelfHosted = 'self_hosted';
    case Internal = 'internal';
    case Trial = 'trial';
    case Development = 'development';
}

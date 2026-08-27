<?php

namespace App\Filament\Auth;

use App\Domain\Masters\Services\CompanyIdentity;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * The login screen, on a layout of its own.
 *
 * Everything that authenticates — the form, its validation, the throttle, the
 * multi-factor challenge — is inherited untouched. What is replaced is the
 * page around it, so there is nothing here for a redesign to get wrong about
 * signing in.
 */
class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    protected static string $layout = 'filament.auth.layout';

    /**
     * The layout draws the logo itself, on the left, at a size the stock header
     * has no setting for. Returning false stops it being drawn twice.
     */
    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string
    {
        return 'Sign in';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function identity(): CompanyIdentity
    {
        return app(CompanyIdentity::class);
    }

    /** @return array<string, mixed> */
    protected function getLayoutData(): array
    {
        return [
            ...parent::getLayoutData(),
            'identity' => $this->identity(),
        ];
    }
}

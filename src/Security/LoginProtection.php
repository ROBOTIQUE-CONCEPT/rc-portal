<?php

declare(strict_types=1);

namespace RC\Portal\Security;

use RC\Portal\Core\CoreBridge;
use Throwable;

/**
 * Bridges the Portal login form to RC Core's canonical Turnstile services.
 *
 * RC Portal owns the `portal_login` security context lifecycle, while RC Core
 * remains the single authority for Turnstile configuration, rendering and
 * server-side verification.
 */
final class LoginProtection
{
    private const CONTEXT = 'portal_login';

    public function __construct(private readonly CoreBridge $core)
    {
    }

    /**
     * Renders the canonical RC Core Turnstile challenge inside the theme hook.
     */
    public function render(): void
    {
        try {
            $runtime = rc_core();
            if (! is_object($runtime) || ! method_exists($runtime, 'turnstileRenderer')) {
                $this->core->warning(
                    'Service TurnstileRenderer indisponible pour le login Portal.',
                    'turnstile_renderer_unavailable'
                );
                return;
            }

            $renderer = $runtime->turnstileRenderer();
            if (! is_object($renderer) || ! method_exists($renderer, 'render')) {
                $this->core->warning(
                    'Renderer Turnstile invalide pour le login Portal.',
                    'turnstile_renderer_invalid'
                );
                return;
            }

            echo $renderer->render(self::CONTEXT, ['class' => 'rc-portal__turnstile']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted RC Core renderer.
        } catch (Throwable $exception) {
            $this->core->warning(
                'Échec du rendu Turnstile sur le login Portal.',
                'turnstile_render_failed',
                ['error' => $exception->getMessage()]
            );
        }
    }

    /**
     * Validates the current login request with RC Core.
     *
     * @return array{success:bool,message:string,error_codes:array<int,string>}
     */
    public function verify(): array
    {
        try {
            $runtime = rc_core();
            if (! is_object($runtime) || ! method_exists($runtime, 'turnstileVerifier')) {
                return $this->unavailable();
            }

            $verifier = $runtime->turnstileVerifier();
            if (! is_object($verifier) || ! method_exists($verifier, 'verifyRequest')) {
                return $this->unavailable();
            }

            /** @var array{success:bool,message:string,error_codes:array<int,string>} $result */
            $result = $verifier->verifyRequest(self::CONTEXT);
            return $result;
        } catch (Throwable $exception) {
            $this->core->warning(
                'Échec de la vérification Turnstile sur le login Portal.',
                'turnstile_verify_failed',
                ['error' => $exception->getMessage()]
            );

            return [
                'success' => false,
                'message' => __('La validation de sécurité est indisponible. Merci de réessayer.', 'rc-portal'),
                'error_codes' => ['service-unavailable'],
            ];
        }
    }

    /** @return array{success:bool,message:string,error_codes:array<int,string>} */
    private function unavailable(): array
    {
        $this->core->warning(
            'Service TurnstileVerifier indisponible pour le login Portal.',
            'turnstile_verifier_unavailable'
        );

        // Portal login is security-sensitive: fail closed if the Core service
        // unexpectedly disappears. When Turnstile itself is disabled, the
        // canonical verifier returns success and login continues normally.
        return [
            'success' => false,
            'message' => __('La validation de sécurité est indisponible. Merci de réessayer.', 'rc-portal'),
            'error_codes' => ['service-unavailable'],
        ];
    }
}

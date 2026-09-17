<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use InvalidArgumentException;
use RuntimeException;

final class RepositoryUpdateService
{
    private RepositoryUpdateHelper $helper;
    private SuperAdminReauthenticationService $reauth;

    public function __construct(private DriveApplication $app)
    {
        $this->helper = new RepositoryUpdateHelper();
        $this->reauth = new SuperAdminReauthenticationService($app);
    }

    public function check(): array
    {
        if (!$this->helper->available()) {
            throw new RuntimeException(
                'ArcadeCloud Updater no está instalado en este nodo. Ejecuta el instalador privilegiado una sola vez desde el servidor.'
            );
        }
        $state = $this->helper->check();
        $state['reauth'] = $this->reauth->state();
        return $state;
    }

    public function update(string $expectedRemoteSha, string $currentPassword): array
    {
        $expectedRemoteSha = strtolower(trim($expectedRemoteSha));
        if (!preg_match('/\A[a-f0-9]{40}\z/', $expectedRemoteSha)) {
            throw new InvalidArgumentException('La actualización seleccionada ya no es válida. Vuelve a buscar actualizaciones.');
        }
        if (!$this->helper->available()) {
            throw new RuntimeException('ArcadeCloud Updater no está instalado en este nodo.');
        }

        $this->reauth->requireRecent($currentPassword);
        $result = $this->helper->update($expectedRemoteSha);
        $result['reauth'] = $this->reauth->state();
        return $result;
    }
}

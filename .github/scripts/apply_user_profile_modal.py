from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one match, got {count}")
    file.write_text(text.replace(old, new, 1))


replace_once(
    'drive/src/Core/DriveApplication.php',
    'use ArcadeCloud\\Drive\\Aws\\RdsGateway;\n',
    'use ArcadeCloud\\Drive\\Aws\\RdsGateway;\nuse ArcadeCloud\\Drive\\Aws\\SesEmailService;\n'
)
replace_once(
    'drive/src/Core/DriveApplication.php',
    'use ArcadeCloud\\Drive\\Security\\AuthenticationService;\n',
    'use ArcadeCloud\\Drive\\Security\\AuthenticationService;\nuse ArcadeCloud\\Drive\\Security\\PasswordChangeService;\n'
)
replace_once(
    'drive/src/Core/DriveApplication.php',
    'use ArcadeCloud\\Drive\\Security\\UserDirectoryRepository;\n',
    'use ArcadeCloud\\Drive\\Security\\UserDirectoryRepository;\nuse ArcadeCloud\\Drive\\Security\\UserProfileRepository;\nuse ArcadeCloud\\Drive\\Security\\UserProfileService;\n'
)
replace_once(
    'drive/src/Core/DriveApplication.php',
    '    private ?AuthenticationService $authenticationService = null;\n',
    '    private ?AuthenticationService $authenticationService = null;\n    private ?UserProfileRepository $userProfileRepository = null;\n    private ?UserProfileService $userProfileService = null;\n    private ?PasswordChangeService $passwordChangeService = null;\n'
)

old_method = '''    public function authenticationService(): AuthenticationService
    {
        return $this->authenticationService ??= new AuthenticationService(
            $this->authenticationRepository(),
            $this->session()
        );
    }
'''
new_method = old_method + '''
    public function userProfileRepository(): UserProfileRepository
    {
        return $this->userProfileRepository ??= new UserProfileRepository($this->db);
    }

    public function userProfileService(): UserProfileService
    {
        return $this->userProfileService ??= new UserProfileService(
            $this->userProfileRepository(),
            $this->s3,
            $this->bucket,
            $this->userStoragePath()
        );
    }

    public function passwordChangeService(): PasswordChangeService
    {
        return $this->passwordChangeService ??= new PasswordChangeService(
            $this->userProfileRepository(),
            $this->session(),
            new SesEmailService()
        );
    }
'''
replace_once('drive/src/Core/DriveApplication.php', old_method, new_method)

old_identity = '''$userIdentifier = $session->userName();
$userAlias = \\ArcadeCloud\\Drive\\View\\UserIdentityPresenter::alias($userIdentifier);
$userInitials = \\ArcadeCloud\\Drive\\View\\UserIdentityPresenter::initials($userIdentifier);
'''
new_identity = '''$userIdentifier = $session->userName();
$profileCsrf = (string)$session->get('profile_csrf', '');
if (!preg_match('/\\A[a-f0-9]{64}\\z/', $profileCsrf)) {
    $profileCsrf = bin2hex(random_bytes(32));
    $session->set('profile_csrf', $profileCsrf);
}

$userAlias = \\ArcadeCloud\\Drive\\View\\UserIdentityPresenter::alias($userIdentifier);
$userInitials = \\ArcadeCloud\\Drive\\View\\UserIdentityPresenter::initials($userIdentifier);
$userAvatarUrl = '';
try {
    $navbarProfile = $app->userProfileService()->profile($userId);
    $userAlias = (string)($navbarProfile['alias'] ?? $userAlias);
    $userInitials = (string)($navbarProfile['initials'] ?? $userInitials);
    $userAvatarUrl = (string)($navbarProfile['avatar_url'] ?? '');
} catch (Throwable) {
    // El Drive sigue disponible con iniciales si el perfil no puede cargarse.
}
'''
replace_once('drive/s3.php', old_identity, new_identity)

old_avatar = '''          <span class="drive-user-avatar rounded-circle mr-2 d-inline-flex align-items-center justify-content-center"
                aria-hidden="true"
                style="width:30px;height:30px;min-width:30px;font-size:.75rem;font-weight:700;border:2px solid rgba(255,255,255,.85);background:rgba(255,255,255,.15);letter-spacing:.02em;">
            <?= htmlspecialchars($userInitials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </span>
          <span class="drive-user-label"><?= htmlspecialchars($userAlias, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>'''
new_avatar = '''          <?php if ($userAvatarUrl !== ''): ?>
            <img src="<?= htmlspecialchars($userAvatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 alt="Perfil"
                 class="drive-user-avatar rounded-circle mr-2"
                 width="30"
                 height="30"
                 style="object-fit:cover;">
          <?php else: ?>
            <span class="drive-user-avatar rounded-circle mr-2 d-inline-flex align-items-center justify-content-center"
                  aria-hidden="true"
                  style="width:30px;height:30px;min-width:30px;font-size:.75rem;font-weight:700;border:2px solid rgba(255,255,255,.85);background:rgba(255,255,255,.15);letter-spacing:.02em;">
              <?= htmlspecialchars($userInitials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </span>
          <?php endif; ?>
          <span class="drive-user-label"><?= htmlspecialchars($userAlias, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>'''
replace_once('drive/s3.php', old_avatar, new_avatar)

replace_once(
    'drive/s3.php',
    '''        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="usuarioMenu">
          <button class="dropdown-item" data-toggle="modal" data-target="#modalEnlacesUtiles">''',
    '''        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="usuarioMenu">
          <button class="dropdown-item" data-toggle="modal" data-target="#modalUserProfile">
            <i class="fas fa-user-circle"></i> Mi perfil
          </button>
          <div class="dropdown-divider"></div>
          <button class="dropdown-item" data-toggle="modal" data-target="#modalEnlacesUtiles">'''
)

replace_once(
    'drive/s3.php',
    '''<div id="bloque-footer">
  <?php include 'bloque_footer.php'; ?>
</div>''',
    '''<?= \\ArcadeCloud\\Drive\\View\\UserProfileModalRenderer::render($profileCsrf) ?>

<div id="bloque-footer">
  <?php include 'bloque_footer.php'; ?>
</div>'''
)

replace_once(
    'drive/s3.php',
    '''<script src="js/estilo.js?v=<?= (int) filemtime(__DIR__ . '/js/estilo.js') ?>"></script>''',
    '''<script src="js/estilo.js?v=<?= (int) filemtime(__DIR__ . '/js/estilo.js') ?>"></script>
<script src="js/profile.js?v=<?= (int) filemtime(__DIR__ . '/js/profile.js') ?>"></script>'''
)

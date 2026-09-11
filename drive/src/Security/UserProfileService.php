<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\View\UserIdentityPresenter;
use Aws\S3\S3Client;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UserProfileService
{
    private const MAX_AVATAR_BYTES = 5 * 1024 * 1024;
    private const MAX_AVATAR_DIMENSION = 4096;
    private const PROFILE_DIR = 'FotosPerfil/';

    public function __construct(
        private UserProfileRepository $repository,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths
    ) {
    }

    public function profile(int $userId): array
    {
        $profile = $this->repository->findById($userId);
        if ($profile === null) {
            throw new RuntimeException('No se encontró el perfil del usuario.');
        }

        $name = trim((string)$profile['firstname'] . ' ' . (string)$profile['lastname']);
        $profile['alias'] = UserIdentityPresenter::alias((string)$profile['email']);
        $profile['initials'] = UserIdentityPresenter::initials(
            $name !== '' ? $name : (string)$profile['email']
        );
        $profile['avatar_url'] = $this->presignedAvatarUrl(
            $userId,
            isset($profile['profilepicture']) ? (string)$profile['profilepicture'] : ''
        );

        unset($profile['password']);
        return $profile;
    }

    public function updatePersonal(int $userId, array $input): array
    {
        $data = UserProfileValidator::personal($input);
        $this->repository->updatePersonal($userId, $data);
        return $this->profile($userId);
    }

    public function uploadAvatar(int $userId, array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Selecciona una imagen válida para el perfil.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0) {
            throw new InvalidArgumentException('No se recibió correctamente la imagen.');
        }
        if ($size > self::MAX_AVATAR_BYTES) {
            throw new InvalidArgumentException('La imagen de perfil no puede exceder 5 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('La imagen debe ser JPG, PNG o WebP.');
        }

        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            throw new InvalidArgumentException('El archivo recibido no es una imagen válida.');
        }
        if ((int)$dimensions[0] > self::MAX_AVATAR_DIMENSION || (int)$dimensions[1] > self::MAX_AVATAR_DIMENSION) {
            throw new InvalidArgumentException('La imagen no puede superar 4096 × 4096 píxeles.');
        }

        $current = $this->repository->findById($userId);
        if ($current === null) {
            throw new RuntimeException('No se encontró el perfil del usuario.');
        }

        $prefix = $this->profilePrefix($userId);
        $newKey = $prefix . 'avatar-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $oldKey = trim((string)($current['profilepicture'] ?? ''));

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $newKey,
            'SourceFile' => $tmp,
            'ContentType' => $mime,
            'CacheControl' => 'private, max-age=300',
            'Metadata' => [
                'user-id' => (string)$userId,
                'purpose' => 'profile-picture',
            ],
        ]);

        try {
            $this->repository->updateProfilePicture($userId, $newKey);
        } catch (Throwable $e) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);
            } catch (Throwable) {
            }
            throw $e;
        }

        if ($oldKey !== '' && $oldKey !== $newKey && str_starts_with($oldKey, $prefix)) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);
            } catch (Throwable) {
            }
        }

        return $this->profile($userId);
    }

    public function removeAvatar(int $userId): array
    {
        $current = $this->repository->findById($userId);
        if ($current === null) {
            throw new RuntimeException('No se encontró el perfil del usuario.');
        }

        $oldKey = trim((string)($current['profilepicture'] ?? ''));
        $prefix = $this->profilePrefix($userId);
        $this->repository->updateProfilePicture($userId, null);

        if ($oldKey !== '' && str_starts_with($oldKey, $prefix)) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);
            } catch (Throwable) {
            }
        }

        return $this->profile($userId);
    }

    private function presignedAvatarUrl(int $userId, string $key): ?string
    {
        $key = trim($key);
        if ($key === '' || !str_starts_with($key, $this->profilePrefix($userId))) {
            return null;
        }

        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $request = $this->s3->createPresignedRequest($command, '+15 minutes');

        return (string)$request->getUri();
    }

    private function profilePrefix(int $userId): string
    {
        return $this->paths->rootForUser($userId) . self::PROFILE_DIR;
    }
}

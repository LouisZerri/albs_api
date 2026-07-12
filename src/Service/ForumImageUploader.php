<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Upload des images du forum DANS LE DOSSIER DU WEB (`alabonnestation/public/uploads/forum`) :
 * stockage unique, images servies par le web (cf. WEB_PUBLIC_URL).
 */
final class ForumImageUploader
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 Mo
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const MAX_IMAGES_PER_POST = 3;

    public function __construct(
        #[Autowire('%kernel.project_dir%/../../alabonnestation/public/uploads/forum')]
        private readonly string $uploadDir,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * @return array{success:bool, error?:string, filename?:string, originalFilename?:string, fileSize?:int}
     */
    public function upload(UploadedFile $file): array
    {
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'error' => 'Format non autorisé (' . implode(', ', self::ALLOWED_EXTENSIONS) . ').'];
        }

        $fileSize = $file->getSize();
        $originalFilename = $file->getClientOriginalName();

        if ($fileSize > self::MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'Fichier trop volumineux (max 5 Mo).'];
        }

        $safeFilename = $this->slugger->slug(pathinfo($originalFilename, PATHINFO_FILENAME));
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }

        try {
            $file->move($this->uploadDir, $newFilename);
        } catch (FileException $e) {
            return ['success' => false, 'error' => 'Erreur lors de l\'upload : ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'filename' => $newFilename,
            'originalFilename' => $originalFilename,
            'fileSize' => $fileSize,
        ];
    }

    public function delete(string $filename): void
    {
        $path = $this->uploadDir . '/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

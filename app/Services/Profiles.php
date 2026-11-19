<?php
declare(strict_types=1);

namespace Sipe\Services;

use Sipe\Middleware\Security;
use Sipe\Models\Database as DB;

final class Profiles
{
    public static function enabled(): bool
    {
        return \config()['profiles'] ?? true;
    }

    public static function select(): string
    {
        return self::enabled() ? ',f.origen AS foto_origen' : '';
    }

    public static function join(): string
    {
        return self::enabled() ? ' LEFT JOIN fotografias_prestatarios f ON f.estudiante_id=e.id' : '';
    }

    public static function date(?string $value): string
    {
        return $value ? \DateTimeImmutable::createFromFormat('!Y-m-d',$value)->format('d/m/Y') : 'Sin información registrada';
    }

    public static function caption(array $person): string
    {
        return match($person['foto_origen'] ?? null) {
            'ILUSTRATIVA'=>'Fotografía ilustrativa',
            'ADMINISTRATIVA'=>'Fotografía registrada por administración',
            default=>'Imagen genérica',
        };
    }

    public static function fields(array $input): array
    {
        $values = [];
        if (array_key_exists('cedula',$input)) {
            $document = Security::text($input['cedula'],40,false);
            if ($document !== '' && !ctype_digit($document)) {
                throw new \DomainException('La cédula debe contener solo dígitos.');
            }
            $values['cedula'] = $document === '' ? null : $document;
        }
        if (array_key_exists('carnet_vigencia',$input)) {
            $date = Security::text($input['carnet_vigencia'],10,false);
            if ($date !== '' && (!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D',$date)
                || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) {
                throw new \DomainException('Introduce una fecha válida para la vigencia del carné.');
            }
            $values['carnet_vigencia'] = $date === '' ? null : $date;
        }
        return $values;
    }

    public static function upload(?array $file): ?array
    {
        if ($file === null || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 1_000_000
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \DomainException('La fotografía debe ser un archivo de hasta 1 MB.');
        }
        $bytes = file_get_contents($file['tmp_name']);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$image || $image[0] > 2048 || $image[1] > 2048
            || !in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)) {
            throw new \DomainException('Usa una imagen JPEG, PNG o WebP de hasta 2048 × 2048 píxeles.');
        }
        return [$image['mime'],$bytes];
    }

    public static function servePhoto(int $id): void
    {
        $photo = DB::one('SELECT e.id,f.mime,f.contenido FROM estudiantes e
            LEFT JOIN fotografias_prestatarios f ON f.estudiante_id=e.id WHERE e.id=?',[$id]);
        if (!$photo) {
            throw new \DomainException('Prestatario no encontrado.',404);
        }
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: '.($photo['mime'] ?? 'image/png'));
        echo $photo['contenido'] ?? file_get_contents(ROOT.'/assets/img/foto-generica.png');
    }
}

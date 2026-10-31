<?php
declare(strict_types=1);
namespace Sipe\Middleware;
final class Security
{
    public static function token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function verify(?string $token): void {
        if (!$token || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) { throw new \DomainException('El formulario venció. Actualiza la página e intenta de nuevo.', 403); }
    }
    public static function admin(array $user): void { if ($user['rol'] !== 'ADMINISTRADOR') { throw new \DomainException('No tienes permiso para esta operación.',403); } }
    public static function email(string $email): string {
        $email=mb_strtolower(trim($email));
        if (strlen($email)>180 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new \DomainException('Ingresa un correo válido.'); }
        return $email;
    }
    public static function password(string $password): void {
        if (mb_strlen($password)<15 || strlen($password)>72) { throw new \DomainException('La contraseña debe tener al menos 15 caracteres y como máximo 72 bytes.'); }
    }
    public static function text(mixed $value, int $max, bool $required=true): string {
        if (!is_scalar($value)) { throw new \DomainException('El campo tiene un formato inválido.'); }
        $value=trim((string)$value);
        if (($required && $value==='') || mb_strlen($value)>$max) { throw new \DomainException("Completa los campos; máximo $max caracteres."); }
        return $value;
    }
    public static function id(mixed $value): int { if (!filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) { throw new \DomainException('Identificador inválido.'); } return (int)$value; }
}

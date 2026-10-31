<?php
declare(strict_types=1);
namespace Sipe\Models;
final class Catalog
{
    // Lista cerrada: los identificadores SQL nunca provienen del formulario.
    public const DEFINITIONS = [
        'estudiantes'=>['title'=>'Estudiantes y prestatarios','fields'=>['codigo_estudiante'=>'Código institucional','nombres'=>'Nombres','apellidos'=>'Apellidos','tipo_persona'=>'Grupo de trabajo','correo'=>'Correo','programa_id'=>'Programa académico'],'required'=>['codigo_estudiante','nombres','apellidos','tipo_persona']],
        'programas_academicos'=>['title'=>'Programas académicos','fields'=>['codigo'=>'Código','nombre'=>'Nombre'],'required'=>['codigo','nombre']],
        'categorias_recursos'=>['title'=>'Categorías','fields'=>['nombre'=>'Nombre','descripcion'=>'Descripción'],'required'=>['nombre']],
        'recursos'=>['title'=>'Inventario','fields'=>['codigo_inventario'=>'Código de inventario','nombre'=>'Nombre','descripcion'=>'Descripción','categoria_id'=>'Categoría','estado_fisico'=>'Estado físico','estado_operativo'=>'Estado operativo'],'required'=>['codigo_inventario','nombre','categoria_id','estado_fisico','estado_operativo']],
        'usuarios'=>['title'=>'Usuarios','fields'=>['nombre_completo'=>'Nombre completo','correo'=>'Correo','rol_id'=>'Rol','password'=>'Contraseña nueva'],'required'=>['nombre_completo','correo','rol_id']],
    ];
    public static function definition(string $table): array { return self::DEFINITIONS[$table] ?? throw new \DomainException('Módulo inexistente.',404); }
    public static function options(string $field): ?array {
        return match($field) {
            'programa_id'=>array_column(Database::all('SELECT id,nombre FROM programas_academicos WHERE activo=1 ORDER BY nombre'),'nombre','id'),
            'tipo_persona'=>['ESTUDIANTE'=>'Estudiante','EGRESADO'=>'Egresado','ADMINISTRATIVO'=>'Administrativo'],
            'categoria_id'=>array_column(Database::all('SELECT id,nombre FROM categorias_recursos WHERE activo=1 ORDER BY nombre'),'nombre','id'),
            'rol_id'=>array_column(Database::all('SELECT id,nombre FROM roles'),'nombre','id'),
            'estado_fisico'=>array_combine(['BUENO','REGULAR','DANADO'],['Bueno','Regular','Dañado']),
            'estado_operativo'=>array_combine(['DISPONIBLE','MANTENIMIENTO','BAJA'],['Disponible','Mantenimiento','Baja']),
            default=>null
        };
    }
}

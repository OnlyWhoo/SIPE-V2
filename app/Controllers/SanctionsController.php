<?php
declare(strict_types=1);

namespace Sipe\Controllers;

use Sipe\Middleware\Security as S;
use Sipe\Models\Database as DB;
use Sipe\Services\{Administration,SearchFilters};

final class SanctionsController
{
    public function handle(array $user, bool $post): void
    {
        S::admin($user);
        $action = $post ? ($_POST['action'] ?? '') : '';
        $url = \url('sanctions');
        if ($action === 'search') {
            SearchFilters::submit('sanctions', $url, ['code']);
        }

        $filters = SearchFilters::saved('sanctions');
        $code = S::text($filters['code'] ?? '', 40, false);
        $filter = $_GET['filter'] ?? '';
        $target = $url.(is_string($filter) && preg_match('/\A[a-f0-9]{32}\z/D',$filter) ? '&filter='.$filter : '');
        $person = $code === '' ? null : DB::one(
            'SELECT id,codigo_estudiante,nombres,apellidos,tipo_persona,activo
             FROM estudiantes WHERE codigo_estudiante=?', [$code]
        );

        $formError = null;
        if ($post) {
            try {
                if ($action === 'resolve') {
                    Administration::resolve($_POST, $user);
                    $_SESSION['flash'] = 'Resolución registrada.';
                } elseif ($action === 'register') {
                    if (!$person) {
                        throw new \DomainException('Busca un usuario por código antes de registrar la sanción.');
                    }
                    Administration::sanction(['estudiante_id'=>$person['id']]+$_POST, $user);
                    $_SESSION['flash'] = 'Sanción registrada.';
                } else {
                    throw new \DomainException('Acción inválida.');
                }
                header('Location: '.$target, true, 303);
                return;
            } catch (\DomainException $error) {
                if ($error->getCode() !== 0) { throw $error; }
                http_response_code(422);
                $formError = $error->getMessage();
            }
        }

        $loans = $person ? DB::all(
            'SELECT p.id,r.nombre AS recurso,r.codigo_inventario,p.fecha_entrega,p.fecha_limite,p.estado,d.fecha_devolucion
             FROM prestamos p JOIN recursos r ON r.id=p.recurso_id
             LEFT JOIN devoluciones d ON d.prestamo_id=p.id
             WHERE p.estudiante_id=? ORDER BY p.fecha_entrega DESC,p.id DESC', [$person['id']]
        ) : [];
        $rows = DB::all("SELECT s.*,CONCAT(e.nombres,' ',e.apellidos) AS estudiante,r.nombre AS recurso
            FROM sanciones s JOIN estudiantes e ON e.id=s.estudiante_id
            LEFT JOIN prestamos p ON p.id=s.prestamo_id LEFT JOIN recursos r ON r.id=p.recurso_id
            ORDER BY s.id DESC LIMIT 100");
        $draft = $action === 'register' ? $_POST : [];
        \view('sanctions', compact('user','target','code','person','loans','rows','formError','draft')+['title'=>'Sanciones']);
    }
}

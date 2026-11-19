<?php
declare(strict_types=1);

namespace Sipe\Services;

final class SearchFilters
{
    public static function apply(string $scope): array
    {
        return $_GET + self::saved($scope);
    }

    public static function saved(string $scope): array
    {
        $token = $_GET['filter'] ?? '';
        $filter = is_string($token) ? ($_SESSION['filters'][$token] ?? null) : null;
        return ($filter['scope'] ?? null) === $scope ? $filter['values'] : [];
    }

    public static function submit(string $scope,string $url,array $keys): never
    {
        $values = [];
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $values[$key] = \Sipe\Middleware\Security::text($_POST[$key],150,false);
            }
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['filters'][$token] = [
            'scope'=>$scope,
            'values'=>$values,
        ];
        $_SESSION['filters'] = array_slice($_SESSION['filters'],-16,null,true);
        header('Location: '.$url.'&filter='.$token,true,303);
        exit;
    }
}

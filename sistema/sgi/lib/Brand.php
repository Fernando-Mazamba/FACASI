<?php
declare(strict_types=1);

/**
 * Marca da aplicação instalável. O manifest é único por instalação (é pedido sem sessão):
 * usa o logótipo/nome da organização definida em "app_brand_org" ou, se só existir uma organização com logótipo, essa.
 * Caso contrário, ícone neutro do SGI (sem inventar marca).
 */
class Brand
{
    public static function org(): ?array
    {
        if (!sgi_is_installed()) {
            return null;
        }
        try {
            $db = sgi_db();
            $id = (int)setting_get('app_brand_org', '0');
            if ($id) {
                $o = $db->one("SELECT * FROM organizations WHERE id = ? AND status = 'ativa'", [$id]);
                if ($o) {
                    return $o;
                }
            }
            $rows = $db->all("SELECT * FROM organizations WHERE status = 'ativa' AND logo_path IS NOT NULL LIMIT 2");
            return count($rows) === 1 ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

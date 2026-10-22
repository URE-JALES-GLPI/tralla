<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Direitos do [URE] Tralla no perfil do GLPI.
 */
class PluginTrallaProfile extends CommonDBTM
{
    public static string $rightname = 'profile';

    public const RIGHT_TRALLA = 'plugin_tralla';
    public const RIGHT_APPROVE = 'plugin_tralla_approve';

    public static function getTypeName($nb = 0)
    {
        return _n('Permissão Tralla', 'Permissões Tralla', $nb, 'tralla');
    }

    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => '[URE] Tralla — Quadros',
                'field'    => self::RIGHT_TRALLA,
                'rights'   => [
                    READ   => __('Ler'),
                    CREATE => __('Criar'),
                    UPDATE => __('Atualizar'),
                    DELETE => __('Excluir'),
                    PURGE  => __('Purgar'),
                ],
                'default'  => READ | CREATE | UPDATE | DELETE,
            ],
            [
                'itemtype' => self::class,
                'label'    => '[URE] Tralla — Aprovações',
                'field'    => self::RIGHT_APPROVE,
                'rights'   => [
                    READ => __('Aprovar / reprovar'),
                ],
                'default'  => READ,
            ],
        ];
    }

    public static function install(): bool
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            self::addDefaultProfileInfos((int) $profile['id'], self::getDefaultRightsMap());
        }
        self::changeProfile();

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        foreach (self::getAllRights() as $right) {
            $DB->delete(ProfileRight::getTable(), ['name' => $right['field']]);
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }

        return true;
    }

    public static function getDefaultRightsMap(): array
    {
        $rights = [];
        foreach (self::getAllRights() as $right) {
            $rights[$right['field']] = $right['default'];
        }

        return $rights;
    }

    public static function addDefaultProfileInfos(int $profiles_id, array $rights): void
    {
        $profileRight = new ProfileRight();
        foreach ($rights as $rightName => $rightValue) {
            if (!countElementsInTable(ProfileRight::getTable(), [
                'profiles_id' => $profiles_id,
                'name'        => $rightName,
            ])) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $rightName,
                    'rights'      => $rightValue,
                ]);
            }
        }
    }

    public static function changeProfile(): void
    {
        global $DB;

        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return;
        }

        foreach (self::getAllRights() as $right) {
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }

        $iterator = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profileId,
                'name'        => array_column(self::getAllRights(), 'field'),
            ],
        ]);

        foreach ($iterator as $row) {
            $_SESSION['glpiactiveprofile'][$row['name']] = (int) $row['rights'];
        }
    }

    public static function getProfileRightValue(int $profiles_id, string $rightName, int $default = 0): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => $rightName,
            ],
            'LIMIT'  => 1,
        ])->current();

        return (is_array($row) && isset($row['rights'])) ? (int) $row['rights'] : $default;
    }

    public static function setProfileRight(int $profiles_id, string $rightName, int $rightValue): void
    {
        global $DB;

        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => $rightName,
            ],
            'LIMIT'  => 1,
        ])->current();

        if ($exists) {
            $DB->update(ProfileRight::getTable(), ['rights' => $rightValue], [
                'profiles_id' => $profiles_id,
                'name'        => $rightName,
            ]);
        } else {
            $DB->insert(ProfileRight::getTable(), [
                'profiles_id' => $profiles_id,
                'name'        => $rightName,
                'rights'      => $rightValue,
            ]);
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            return "<span class='d-inline-flex align-items-center gap-1'><i class='ti ti-layout-kanban'></i><span>Tralla</span></span>";
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if (!($item instanceof Profile) || !$item->canView()) {
            return false;
        }

        $profilesId = (int) $item->getID();
        self::addDefaultProfileInfos($profilesId, self::getDefaultRightsMap());

        $right = self::getProfileRightValue($profilesId, self::RIGHT_TRALLA, 0);
        $approve = self::getProfileRightValue($profilesId, self::RIGHT_APPROVE, 0);
        $canEdit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        $formAction = $CFG_GLPI['root_doc'] . '/plugins/tralla/front/profile.form.php';

        echo "<form method='post' action='" . tralla_h($formAction) . "'>";
        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr class='headerRow'><th colspan='2'>[URE] Tralla — Permissões do perfil</th></tr>";

        echo "<tr class='tab_bg_1'><td width='45%'><strong>Acesso aos quadros</strong><br><small>Menu Ferramentas → Quadros Tralla</small></td><td>";
        if ($canEdit) {
            $options = [
                0                                       => '— Sem acesso —',
                READ                                    => 'Visualizar',
                READ | CREATE                           => 'Visualizar e criar',
                READ | CREATE | UPDATE                  => 'Visualizar, criar e editar',
                READ | CREATE | UPDATE | DELETE         => 'Visualizar, criar, editar e excluir',
                READ | CREATE | UPDATE | DELETE | PURGE => 'Acesso total',
            ];
            Dropdown::showFromArray('rights', $options, ['value' => $right]);
        } else {
            echo (int) $right;
        }
        echo '</td></tr>';

        echo "<tr class='tab_bg_2'><td><strong>Aprovar / reprovar manutenção</strong><br><small>Libera os botões Aprovar e Reprovar nos cards de aprovação</small></td><td>";
        if ($canEdit) {
            Dropdown::showFromArray('approve_rights', [0 => 'Não', 1 => 'Sim'], ['value' => $approve > 0 ? 1 : 0]);
        } else {
            echo $approve > 0 ? 'Sim' : 'Não';
        }
        echo '</td></tr>';

        if ($canEdit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
            echo Html::hidden('profiles_id', ['value' => $profilesId]);
            echo Html::hidden('_glpi_csrf_token', ['value' => tralla_csrf_token()]);
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar permissões</button>";
            echo '</td></tr>';
        }

        echo '</table></div>';
        Html::closeForm();

        return true;
    }
}

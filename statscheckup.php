<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class statscheckup extends Module
{
    private $html = '';

    public function __construct()
    {
        $this->name = 'statscheckup';
        $this->tab = 'administration';
        $this->version = '2.0.2';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->trans('Catalog evaluation', [], 'Modules.Statscheckup.Admin');
        $this->description = $this->trans('Enrich your stats, give your catalog a quick evaluation to better analyze your activity.', [], 'Modules.Statscheckup.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        $confs = [
            'CHECKUP_DESCRIPTIONS_LT' => 100,
            'CHECKUP_DESCRIPTIONS_GT' => 400,
            'CHECKUP_IMAGES_LT' => 1,
            'CHECKUP_IMAGES_GT' => 2,
            'CHECKUP_SALES_LT' => 1,
            'CHECKUP_SALES_GT' => 2,
            'CHECKUP_STOCK_LT' => 1,
            'CHECKUP_STOCK_GT' => 3,
        ];
        foreach ($confs as $confname => $confdefault) {
            if (!Configuration::get($confname)) {
                Configuration::updateValue($confname, (int) $confdefault);
            }
        }

        return parent::install() && $this->registerHook('displayAdminStatsModules');
    }

    public function hookDisplayAdminStatsModules()
    {
        if (Tools::isSubmit('submitCheckup')) {
            $confs = [
                'CHECKUP_DESCRIPTIONS_LT',
                'CHECKUP_DESCRIPTIONS_GT',
                'CHECKUP_IMAGES_LT',
                'CHECKUP_IMAGES_GT',
                'CHECKUP_SALES_LT',
                'CHECKUP_SALES_GT',
                'CHECKUP_STOCK_LT',
                'CHECKUP_STOCK_GT',
            ];
            foreach ($confs as $confname) {
                Configuration::updateValue($confname, (int) Tools::getValue($confname));
            }
            echo '<div class="conf confirm"> ' . $this->trans('Settings updated', [], 'Admin.Global') . '</div>';
        }

        if (Tools::isSubmit('submitCheckupOrder')) {
            $this->context->cookie->checkup_order = (int) Tools::getValue('submitCheckupOrder');
            echo '<div class="conf confirm"> ' . $this->trans('Settings updated', [], 'Admin.Global') . '</div>';
        }

        if (!isset($this->context->cookie->checkup_order)) {
            $this->context->cookie->checkup_order = 1;
        }

        $db = Db::getInstance((bool) _PS_USE_SQL_SLAVE_);
        $employee = Context::getContext()->employee;
        $prop30 = ((strtotime($employee->stats_date_to . ' 23:59:59') - strtotime($employee->stats_date_from . ' 00:00:00')) / 60 / 60 / 24) / 30;

        // Get languages
        $sql = 'SELECT l.*
				FROM ' . _DB_PREFIX_ . 'lang l'
                . Shop::addSqlAssociation('lang', 'l');
        $languages = $db->executeS($sql);

        $array_colors = [
            0 => '<img src="../modules/' . $this->name . '/img/red.png" alt="' . $this->trans('Bad', [], 'Modules.Statscheckup.Admin') . '" />',
            1 => '<img src="../modules/' . $this->name . '/img/orange.png" alt="' . $this->trans('Average', [], 'Modules.Statscheckup.Admin') . '" />',
            2 => '<img src="../modules/' . $this->name . '/img/green.png" alt="' . $this->trans('Good', [], 'Modules.Statscheckup.Admin') . '" />',
        ];
        $token_products = Tools::getAdminToken('AdminProducts' . (int) Tab::getIdFromClassName('AdminProducts') . (int) Context::getContext()->employee->id);
        $divisor = 4;
        $totals = ['products' => 0, 'active' => 0, 'images' => 0, 'sales' => 0, 'stock' => 0];
        foreach ($languages as $language) {
            ++$divisor;
            $totals['description_' . $language['iso_code']] = 0;
        }

        $order_by = 'p.id_product';
        if ($this->context->cookie->checkup_order == 2) {
            $order_by = 'pl.name';
        } elseif ($this->context->cookie->checkup_order == 3) {
            $order_by = 'nbSales DESC';
        }

        // Get products stats
        $sql = 'SELECT p.id_product, product_shop.active, pl.name, (
					SELECT COUNT(*)
					FROM ' . _DB_PREFIX_ . 'image i
					' . Shop::addSqlAssociation('image', 'i') . '
					WHERE i.id_product = p.id_product
				) as nbImages, (
					SELECT SUM(od.product_quantity)
					FROM ' . _DB_PREFIX_ . 'orders o
					LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od ON o.id_order = od.id_order
					WHERE od.product_id = p.id_product
						AND o.invoice_date BETWEEN ' . ModuleGraph::getDateBetween() . '
						' . Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o') . '
				) as nbSales,
				IFNULL(stock.quantity, 0) as stock
				FROM ' . _DB_PREFIX_ . 'product p
				' . Shop::addSqlAssociation('product', 'p') . '
				' . Product::sqlStock('p', 0) . '
				LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
					ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int) $this->context->language->id . Shop::addSqlRestrictionOnLang('pl') . ')
				ORDER BY ' . $order_by;
        $result = $db->executeS($sql);

        if (!$result) {
            return $this->trans('No product was found.', [], 'Modules.Statscheckup.Admin');
        }

        $array_conf = [
            'DESCRIPTIONS' => ['name' => $this->trans('Descriptions', [], 'Modules.Statscheckup.Admin'), 'text' => $this->trans('chars (without HTML)', [], 'Modules.Statscheckup.Admin')],
            'IMAGES' => ['name' => $this->trans('Images', [], 'Admin.Global'), 'text' => $this->trans('images', [], 'Admin.Global')],
            'SALES' => ['name' => $this->trans('Sales', [], 'Admin.Global'), 'text' => $this->trans('orders / month', [], 'Modules.Statscheckup.Admin')],
            'STOCK' => ['name' => $this->trans('Available quantity for sale', [], 'Admin.Global'), 'text' => $this->trans('items', [], 'Modules.Statscheckup.Admin')],
        ];

        $this->html = '
		<div class="panel-heading">'
            . $this->displayName . '
		</div>
		<form action="' . Tools::safeOutput(AdminController::$currentIndex . '&token=' . Tools::getValue('token') . '&module=' . $this->name) . '" method="post" class="checkup form-horizontal">
			<table class="table checkup">
				<thead>
					<tr>
						<th></th>
						<th><span class="title_box active">' . $array_colors[0] . ' ' . $this->trans('Not enough', [], 'Modules.Statscheckup.Admin') . '</span></th>
						<th><span class="title_box active">' . $array_colors[2] . ' ' . $this->trans('Alright', [], 'Modules.Statscheckup.Admin') . '</span></th>
					</tr>
				</thead>';
        foreach ($array_conf as $conf => $translations) {
            $this->html .= '
				<tbody>
					<tr>
						<td>
							<label class="control-label col-lg-12">' . $translations['name'] . '</label>
						</td>
						<td>
							<div class="row">
								<div class="col-lg-11 input-group">
									<span class="input-group-addon">' . $this->trans('Less than', [], 'Modules.Statscheckup.Admin') . '</span>
									<input type="text" name="CHECKUP_' . $conf . '_LT" value="' . Tools::safeOutput(Tools::getValue('CHECKUP_' . $conf . '_LT', Configuration::get('CHECKUP_' . $conf . '_LT'))) . '" />
									<span class="input-group-addon">' . $translations['text'] . '</span>
								 </div>
							 </div>
						</td>
						<td>
							<div class="row">
								<div class="col-lg-12 input-group">
									<span class="input-group-addon">' . $this->trans('Greater than', [], 'Modules.Statscheckup.Admin') . '</span>
									<input type="text" name="CHECKUP_' . $conf . '_GT" value="' . Tools::safeOutput(Tools::getValue('CHECKUP_' . $conf . '_GT', Configuration::get('CHECKUP_' . $conf . '_GT'))) . '" />
									<span class="input-group-addon">' . $translations['text'] . '</span>
								 </div>
							 </div>
						</td>
					</tr>
				</tbody>';
        }
        $this->html .= '</table>
			<button type="submit" name="submitCheckup" class="btn btn-default pull-right">
				<i class="icon-save"></i> ' . $this->trans('Save', [], 'Admin.Actions') . '
			</button>
		</form>
		<form action="' . Tools::safeOutput(AdminController::$currentIndex . '&token=' . Tools::getValue('token') . '&module=' . $this->name) . '" method="post" class="form-horizontal alert">
			<div class="row">
				<div class="col-lg-12">
					<label class="control-label pull-left">' . $this->trans('Order by', [], 'Modules.Statscheckup.Admin') . '</label>
					<div class="col-lg-3">
						<select name="submitCheckupOrder" onchange="this.form.submit();">
							<option value="1">' . $this->trans('ID', [], 'Admin.Global') . '</option>
							<option value="2" ' . ($this->context->cookie->checkup_order == 2 ? 'selected="selected"' : '') . '>' . $this->trans('Name', [], 'Admin.Global') . '</option>
							<option value="3" ' . ($this->context->cookie->checkup_order == 3 ? 'selected="selected"' : '') . '>' . $this->trans('Sales', [], 'Admin.Global') . '</option>
						</select>
					</div>
				</div>
			</div>
		</form>
		<div style="overflow-x:auto">
		<table class="table checkup2">
			<thead>
				<tr>
					<th><span class="title_box active">' . $this->trans('ID', [], 'Admin.Global') . '</span></th>
					<th><span class="title_box active">' . $this->trans('Item', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Active', [], 'Admin.Global') . '</span></th>';
        foreach ($languages as $language) {
            $this->html .= '<th><span class="title_box active">' . $this->trans('Desc.', [], 'Modules.Statscheckup.Admin') . ' (' . Tools::strtoupper($language['iso_code']) . ')</span></th>';
        }
        $this->html .= '
					<th class="center"><span class="title_box active">' . $this->trans('Images', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Sales', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Available quantity for sale', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Global', [], 'Modules.Statscheckup.Admin') . '</span></th>
				</tr>
			</thead>
			<tbody>';
        foreach ($result as $row) {
            ++$totals['products'];
            $scores = [
                'active' => ($row['active'] ? 2 : 0),
                'images' => ($row['nbImages'] < Configuration::get('CHECKUP_IMAGES_LT') ? 0 : ($row['nbImages'] > Configuration::get('CHECKUP_IMAGES_GT') ? 2 : 1)),
                'sales' => (($row['nbSales'] * $prop30 < Configuration::get('CHECKUP_SALES_LT')) ? 0 : (($row['nbSales'] * $prop30 > Configuration::get('CHECKUP_SALES_GT')) ? 2 : 1)),
                'stock' => (($row['stock'] < Configuration::get('CHECKUP_STOCK_LT')) ? 0 : (($row['stock'] > Configuration::get('CHECKUP_STOCK_GT')) ? 2 : 1)),
            ];
            $totals['active'] += (int) $scores['active'];
            $totals['images'] += (int) $scores['images'];
            $totals['sales'] += (int) $scores['sales'];
            $totals['stock'] += (int) $scores['stock'];
            $descriptions = $db->executeS('
				SELECT l.iso_code, pl.description
				FROM ' . _DB_PREFIX_ . 'product_lang pl
				LEFT JOIN ' . _DB_PREFIX_ . 'lang l
					ON pl.id_lang = l.id_lang
				WHERE id_product = ' . (int) $row['id_product'] . Shop::addSqlRestrictionOnLang('pl'));
            foreach ($descriptions as $description) {
                if (isset($description['iso_code']) && isset($description['description'])) {
                    $row['desclength_' . $description['iso_code']] = Tools::strlen(strip_tags($description['description']));
                }
                if (isset($description['iso_code'])) {
                    $scores['description_' . $description['iso_code']] = ($row['desclength_' . $description['iso_code']] < Configuration::get('CHECKUP_DESCRIPTIONS_LT') ? 0 : ($row['desclength_' . $description['iso_code']] > Configuration::get('CHECKUP_DESCRIPTIONS_GT') ? 2 : 1));
                    $totals['description_' . $description['iso_code']] += $scores['description_' . $description['iso_code']];
                }
            }
            $scores['average'] = array_sum($scores) / $divisor;
            $scores['average'] = ($scores['average'] < 1 ? 0 : ($scores['average'] > 1.5 ? 2 : 1));

            $this->html .= '
				<tr>
					<td>' . $row['id_product'] . '</td>
					<td><a href="' . Tools::safeOutput('index.php?tab=AdminProducts&updateproduct&id_product=' . $row['id_product'] . '&token=' . $token_products) . '">' . Tools::substr($row['name'], 0, 42) . '</a></td>
					<td class="center">' . $array_colors[$scores['active']] . '</td>';
            foreach ($languages as $language) {
                if (isset($row['desclength_' . $language['iso_code']])) {
                    $this->html .= '<td class="center">' . (int) $row['desclength_' . $language['iso_code']] . ' ' . $array_colors[$scores['description_' . $language['iso_code']]] . '</td>';
                } else {
                    $this->html .= '<td>0 ' . $array_colors[0] . '</td>';
                }
            }
            $this->html .= '
					<td class="center">' . (int) $row['nbImages'] . ' ' . $array_colors[$scores['images']] . '</td>
					<td class="center">' . (int) $row['nbSales'] . ' ' . $array_colors[$scores['sales']] . '</td>
					<td class="center">' . (int) $row['stock'] . ' ' . $array_colors[$scores['stock']] . '</td>
					<td class="center">' . $array_colors[$scores['average']] . '</td>
				</tr>';
        }

        $this->html .= '</tbody>';

        $totals['active'] = $totals['active'] / $totals['products'];
        $totals['active'] = ($totals['active'] < 1 ? 0 : ($totals['active'] > 1.5 ? 2 : 1));
        $totals['images'] = $totals['images'] / $totals['products'];
        $totals['images'] = ($totals['images'] < 1 ? 0 : ($totals['images'] > 1.5 ? 2 : 1));
        $totals['sales'] = $totals['sales'] / $totals['products'];
        $totals['sales'] = ($totals['sales'] < 1 ? 0 : ($totals['sales'] > 1.5 ? 2 : 1));
        $totals['stock'] = $totals['stock'] / $totals['products'];
        $totals['stock'] = ($totals['stock'] < 1 ? 0 : ($totals['stock'] > 1.5 ? 2 : 1));
        foreach ($languages as $language) {
            $totals['description_' . $language['iso_code']] = $totals['description_' . $language['iso_code']] / $totals['products'];
            $totals['description_' . $language['iso_code']] = ($totals['description_' . $language['iso_code']] < 1 ? 0 : ($totals['description_' . $language['iso_code']] > 1.5 ? 2 : 1));
        }
        $totals['average'] = array_sum($totals) / $divisor;
        $totals['average'] = ($totals['average'] < 1 ? 0 : ($totals['average'] > 1.5 ? 2 : 1));

        $this->html .= '
			<tfoot>
				<tr>
					<th colspan="2"></th>
					<th class="center"><span class="title_box active">' . $this->trans('Active', [], 'Admin.Global') . '</span></th>';
        foreach ($languages as $language) {
            $this->html .= '<th class="center"><span class="title_box active">' . $this->trans('Desc.', [], 'Modules.Statscheckup.Admin') . ' (' . Tools::strtoupper($language['iso_code']) . ')</span></th>';
        }
        $this->html .= '
					<th class="center"><span class="title_box active">' . $this->trans('Images', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Sales', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Available quantity for sale', [], 'Admin.Global') . '</span></th>
					<th class="center"><span class="title_box active">' . $this->trans('Global', [], 'Modules.Statscheckup.Admin') . '</span></th>
				</tr>
				<tr>
					<td colspan="2"></td>
					<td class="center">' . $array_colors[$totals['active']] . '</td>';
        foreach ($languages as $language) {
            $this->html .= '<td class="center">' . $array_colors[$totals['description_' . $language['iso_code']]] . '</td>';
        }
        $this->html .= '
					<td class="center">' . $array_colors[$totals['images']] . '</td>
					<td class="center">' . $array_colors[$totals['sales']] . '</td>
					<td class="center">' . $array_colors[$totals['stock']] . '</td>
					<td class="center">' . $array_colors[$totals['average']] . '</td>
				</tr>
			</tfoot>
		</table></div>';

        return $this->html;
    }
}

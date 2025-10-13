<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * 	\file		admin/arronditotal.php
 * 	\ingroup	arronditotal
 * 	\brief		This file is an example module setup page
 * 				Put some comments here
 */
// Dolibarr environment
$res = @include("../../main.inc.php"); // From htdocs directory
if (! $res) {
    $res = @include("../../../main.inc.php"); // From "custom" directory
}

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/arronditotal.lib.php';

// Translations
$langs->load("arronditotal@arronditotal");

// Access control
if (! $user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'alpha');

/*
 * Actions
 */
if (preg_match('/set_(.*)/',$action,$reg))
{
	$code=$reg[1];
	$value = GETPOST($code, 'array');
	// Normalize multiselect values if provided (EN)
	// Normaliser les valeurs multi-sélection si fournies (FR)
	if (is_array($value))
	{
	$value = array_filter(array_map('intval', $value));
	$value = implode(',', $value);
	}
	else
	{
	$value = GETPOST($code);
	}
	if (dolibarr_set_const($db, $code, $value, 'chaine', 0, '', $conf->entity) > 0)
	{
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
	}
	else
	{
	dol_print_error($db);
	}
}

if (preg_match('/del_(.*)/',$action,$reg))
{
	$code=$reg[1];
	if (dolibarr_del_const($db, $code, 0) > 0)
	{
		Header("Location: ".$_SERVER["PHP_SELF"]);
		exit;
	}
	else
	{
		dol_print_error($db);
	}
}

/*
 * View
 */
$page_name = "arronditotalSetup";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">'
    . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup.png');
$newToken = function_exists('newToken')?newToken():$_SESSION['newtoken'];

// Configuration header
$head = arronditotalAdminPrepareHead();
print dol_get_fiche_head(
    $head,
    'settings',
    $langs->trans("Module104870Name"),
    -1,
    "arronditotal@arronditotal"
);

// Setup page goes here
$form=new Form($db);
$var=false;

$ignoredProductsSelected = array();
// Retrieve ignored product/service identifiers from configuration (EN)
// Récupérer les identifiants de produits/services ignorés depuis la configuration (FR)
$ignoredProductsRaw = explode(',', (string) getDolGlobalString('ARRONDITOTAL_PRODUITS_IGNORES'));
foreach ($ignoredProductsRaw as $ignoredProductRaw)
	{
	$ignoredProductId = (int) trim($ignoredProductRaw);
	if ($ignoredProductId > 0)
	{
	$ignoredProductsSelected[$ignoredProductId] = $ignoredProductId;
	}
}

$ignoredProductsOptions = array();
// Build the selectable list of products/services to ignore (EN)
// Construire la liste sélectionnable des produits/services à ignorer (FR)
$sql = 'SELECT rowid, ref, label, fk_product_type FROM '.MAIN_DB_PREFIX."product";
$sql .= ' WHERE entity IN ('.getEntity('product', 1).')';
$sql .= ' ORDER BY ref ASC';
$resql = $db->query($sql);
if ($resql)
	{
	while ($obj = $db->fetch_object($resql))
	{
	$typeLabel = ((int) $obj->fk_product_type === 1) ? $langs->trans('Service') : $langs->trans('Product');
	$ignoredProductsOptions[$obj->rowid] = dol_escape_htmltag($obj->ref.' - '.$obj->label.' ('.$typeLabel.')');
	}
}
else
{
	dol_print_error($db);
}
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameters").'</td>'."\n";
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="center" width="100">'.$langs->trans("Value").'</td>'."\n";

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalModeB2B").'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_B2B">';
print $form->selectyesno("ARRONDITOTAL_B2B", getDolGlobalString('ARRONDITOTAL_B2B'),1);
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalAddButtonOnPropal").'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_ADD_BUTTON_ON_PROPAL">';
print $form->selectyesno("ARRONDITOTAL_ADD_BUTTON_ON_PROPAL", getDolGlobalString('ARRONDITOTAL_ADD_BUTTON_ON_PROPAL'),1);
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalAddButtonOnOrder").'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_ADD_BUTTON_ON_ORDER">';
print $form->selectyesno("ARRONDITOTAL_ADD_BUTTON_ON_ORDER", getDolGlobalString('ARRONDITOTAL_ADD_BUTTON_ON_ORDER') ,1);
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalAddButtonOnInvoice").'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_ADD_BUTTON_ON_INVOICE">';
print $form->selectyesno("ARRONDITOTAL_ADD_BUTTON_ON_INVOICE", getDolGlobalString('ARRONDITOTAL_ADD_BUTTON_ON_INVOICE'),1);
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalUpdateLineWithQty").'</td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_QTY_NEEDED_TO_UPDATE">';
print '<input type="text" name="ARRONDITOTAL_QTY_NEEDED_TO_UPDATE" value="' . getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE').'" size="5" />&nbsp;';
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

$var=!$var;
print '<tr '.$bc[$var].'>';
print '<td>'.$langs->trans("arronditotalProduitsIgnores").'<br><span class="opacitymedium">'.$langs->trans("arronditotalProduitsIgnoresHelp").'</span></td>';
print '<td align="center" width="20">&nbsp;</td>';
print '<td align="right" width="300">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$newToken.'">';
print '<input type="hidden" name="action" value="set_ARRONDITOTAL_PRODUITS_IGNORES">';
// Render multi-select using Dolibarr standard widget (EN)
// Afficher la multi-sélection via le composant standard Dolibarr (FR)
print $form->multiselectarray('ARRONDITOTAL_PRODUITS_IGNORES', $ignoredProductsOptions, $ignoredProductsSelected, 0, 0, 'minwidth300', 0, 0, '', 0, '', '', '', 1);
print '&nbsp;';
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</form>';
print '</td></tr>';

print '</table>';

// Page end
print dol_get_fiche_end(-1);

llxFooter();

$db->close();

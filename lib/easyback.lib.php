<?php
/* Copyright (C) 2026		SuperAdmin
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    easyback/lib/easyback.lib.php
 * \ingroup easyback
 * \brief   Library files with common functions for EasyBack
 */

/**
 * List the credit notes of a source invoice: the drafts (an unfinished return,
 * rebuilt on the next attempt) or the validated ones (returns already done).
 * Abandoned credit notes are ignored.
 *
 * @param DoliDB $db         Database handler
 * @param bool   $isSupplier True for supplier invoice, false for customer
 * @param int    $facid      Source invoice id
 * @param bool   $draft      True for the drafts, false for the validated ones
 * @return int[]             Credit note ids, oldest first
 */
function easyback_get_creditnotes($db, $isSupplier, $facid, $draft)
{
	$table = $isSupplier ? 'facture_fourn' : 'facture';
	$sql = "SELECT rowid FROM ".$db->prefix().$table;
	$sql .= " WHERE fk_facture_source = ".((int) $facid)." AND type = 2";
	$sql .= " AND fk_statut ".($draft ? "= 0" : "IN (1, 2)");
	$sql .= " AND entity IN (".getEntity($table).")";
	$sql .= " ORDER BY rowid";

	$ids = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
	}
	return $ids;
}

/**
 * Load the source invoice of a return and check it can be returned by the user:
 * access to the record (entity, thirdparty restrictions), validated or paid status and
 * not a credit note itself. Facture::fetch($id) does not filter on entity, so this check
 * is mandatory before any action.
 *
 * @param DoliDB $db         Database handler
 * @param User   $user       User
 * @param bool   $isSupplier True for supplier invoice, false for customer
 * @param int    $facid      Source invoice id
 * @param string $errmsg     Output error message
 * @return Facture|FactureFournisseur|null Loaded invoice, or null if refused
 */
function easyback_fetch_source_invoice($db, $user, $isSupplier, $facid, &$errmsg)
{
	global $langs;

	$errmsg = '';
	if ($facid <= 0) {
		$errmsg = $langs->trans('ErrorBadParameters');
		return null;
	}

	if ($isSupplier) {
		$ok = restrictedArea($user, 'fournisseur', $facid, 'facture_fourn', 'facture', 'fk_soc', 'rowid', 0, 1, 'read');
		$src = new FactureFournisseur($db);
		$table = 'facture_fourn';
	} else {
		$ok = restrictedArea($user, 'facture', $facid, '', '', 'fk_soc', 'rowid', 0, 1, 'read');
		$src = new Facture($db);
		$table = 'facture';
	}
	if (!$ok || $src->fetch($facid) <= 0) {
		$errmsg = $langs->trans('ErrorRecordNotFound');
		return null;
	}
	if (!in_array((int) $src->entity, array_map('intval', explode(',', getEntity($table))))) {
		$errmsg = $langs->trans('ErrorRecordNotFound');
		return null;
	}
	// Only a validated or paid invoice that is not a credit note can be returned.
	if ((int) $src->type == 2 || !in_array((int) $src->status, array(1, 2))) {
		$errmsg = $langs->trans('EasyBackErrorFacturaOrigen');
		return null;
	}

	return $src;
}

/**
 * POS context of a return, computed on the server (never trusted from the request):
 * terminal of the session and its stock setup.
 *
 * @param User   $user   User
 * @param string $epopup Popup mode of the request ('colorbox' = TakePOS)
 * @return array{terminal:string,noreturnstock:int,idwarehouse:int}
 */
function easyback_get_pos_context($user, $epopup)
{
	$ctx = array('terminal' => '', 'noreturnstock' => 0, 'idwarehouse' => 0);
	if ($epopup != 'colorbox' || empty($_SESSION['takeposterminal']) || !$user->hasRight('takepos', 'run')) {
		return $ctx;
	}
	$term = (string) $_SESSION['takeposterminal'];
	$ctx['terminal'] = $term;
	// Mirror TakePOS: if the terminal does not change stock on sale, the return does not either.
	if (getDolGlobalString('CASHDESK_NO_DECREASE_STOCK'.$term) == '1') {
		$ctx['noreturnstock'] = 1;
	} elseif (getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term) > 0) {
		$ctx['idwarehouse'] = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term);
	}
	return $ctx;
}

/**
 * Check that a warehouse exists in an entity of the user and is open.
 *
 * @param DoliDB $db          Database handler
 * @param int    $idwarehouse Warehouse id
 * @return int                The id if valid, 0 otherwise
 */
function easyback_valid_warehouse($db, $idwarehouse)
{
	if ($idwarehouse <= 0) {
		return 0;
	}
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
	$wh = new Entrepot($db);
	if ($wh->fetch($idwarehouse) <= 0 || (int) $wh->statut == Entrepot::STATUS_CLOSED) {
		return 0;
	}
	if (!in_array((int) $wh->entity, array_map('intval', explode(',', getEntity('stock'))))) {
		return 0;
	}
	return (int) $idwarehouse;
}

/**
 * Whether a bank account can receive the reversal of a credit note: it must be open and
 * in the main currency or in the credit note currency (any other combination would be
 * recorded by the core with a wrong amount).
 *
 * @param DoliDB $db              Database handler
 * @param int    $accountid       Bank account id
 * @param string $invoicecurrency Credit note currency
 * @return bool
 */
function easyback_bank_account_allowed($db, $accountid, $invoicecurrency)
{
	global $conf;

	if ($accountid <= 0) {
		return false;
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$acc = new Account($db);
	// Account::fetch() filters on the entities of the user.
	if ($acc->fetch($accountid) <= 0 || (int) $acc->clos != Account::STATUS_OPEN) {
		return false;
	}
	$acccurrency = ($acc->currency_code ? $acc->currency_code : $conf->currency);
	return ($acccurrency == $conf->currency || $acccurrency == $invoicecurrency);
}

/**
 * Short label of a payment mode: the translation PaymentTypeShort<CODE> when it exists,
 * otherwise the label of the dictionary (modes created by the user, e.g. EFBS, have no
 * translation and would show the raw key).
 *
 * @param DoliDB $db   Database handler
 * @param string $code Code of c_paiement
 * @return string
 */
function easyback_payment_label($db, $code)
{
	global $langs;

	$key = 'PaymentTypeShort'.$code;
	$label = $langs->trans($key);
	if ($label != $key) {
		return $label;
	}
	$dblabel = dol_getIdFromCode($db, $code, 'c_paiement', 'code', 'libelle', 1);
	return (!empty($dblabel) && $dblabel != $code ? (string) $dblabel : (string) $code);
}

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function easybackAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("easyback@easyback");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/easyback/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dolBuildUrl(dol_buildpath("/easyback/admin/myobject_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/easyback/admin/myobjectline_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dolBuildUrl(dol_buildpath("/easyback/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@easyback:/easyback/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@easyback:/easyback/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyback@easyback');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyback@easyback', 'remove');

	return $head;
}

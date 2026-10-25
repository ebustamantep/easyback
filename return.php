<?php
/* Copyright (C) 2026		Edgar Bustamante	<ebustamantep@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    easyback/return.php
 * \ingroup easyback
 * \brief   Popup to process a return. Two modes:
 *          - PARTIAL (invoice card): only the selected lines/quantities are
 *            returned, and the equivalent money is refunded, prorated across
 *            the payment channels the operator marks.
 *          - TOTAL (invoice card "Devolución total" / POS): the whole invoice is
 *            returned (all lines, full quantities) and every payment is refunded
 *            in full, each in its own channel/bank.
 */

define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/factureligne.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.ligne.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/easyback/lib/easyback.lib.php');

// Translation
$langs->loadLangs(array('easyback@easyback', 'bills', 'payments', 'stocks', 'banks', 'errors'));

$facid = GETPOSTINT('facid');
$element = GETPOST('element', 'alpha');
$action = GETPOST('action', 'aZ09');
$emode = GETPOST('mode', 'alpha');                // 'partial' (invoice card) | 'total' (card total / POS)
$elineids = GETPOST('lineid', 'array');
$eqtys = GETPOST('qty', 'array');
$idwarehouse = GETPOSTINT('idwarehouse');
$token = GETPOST('token', 'aZ09');
$epopup = GETPOST('popup', 'aZ09');               // ''/'dialog' = invoice card, 'colorbox' = TakePOS
if ($epopup != 'colorbox' && $epopup != 'dialog') {
	$epopup = '';
}
// POS terminal and its stock setup come from the session/setup, never from the request:
// otherwise a return could be attributed to the cash close of another terminal, or
// skip the stock reintegration.
$eposctx = easyback_get_pos_context($user, $epopup);
$eposterminal = $eposctx['terminal'];             // TakePOS terminal (return made from the POS)
$enoreturnstock = $eposctx['noreturnstock'];      // TakePOS terminal with stock change disabled
if ($eposctx['idwarehouse'] > 0) {
	$idwarehouse = $eposctx['idwarehouse'];
}
$idwarehouse = easyback_valid_warehouse($db, $idwarehouse);

// Mode: explicit, or inferred (a line selection means a partial return).
if ($emode != 'partial' && $emode != 'total') {
	$emode = (is_array($elineids) && count($elineids) > 0) ? 'partial' : 'total';
}
$ispartial = ($emode == 'partial');

// Security
if (!isModEnabled('easyback')) {
	accessforbidden('Module not enabled');
}
global $user;

// CSRF check: opening this page creates and validates a credit note, so the token
// (sent by the invoice card and the POS buttons) is always mandatory.
if (empty($token) || $token != currentToken()) {
	accessforbidden('Invalid token');
}

$isSupplier = ($element == 'facture_fourn' || $element == 'invoice_supplier');

// Stock reintegration. When the return comes from the POS, mirror the terminal
// setup (CASHDESK_NO_DECREASE_STOCK): if the sale did not change stock, the
// return must not change it either.
$estockallowed = (bool) getDolGlobalInt('EASYBACK_REINTEGRO_STOCK') && empty($enoreturnstock);

// Permissions: replicate the native card.php logic (create + validate)
if ($isSupplier) {
	$usercancreate = ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
	$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
		|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate')));
	if (!$usercancreate || !$usercanvalidate) {
		accessforbidden('Not enough permissions');
	}
} else {
	$usercancreate = $user->hasRight('facture', 'creer');
	$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
		|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'validate')));
	if (!$usercancreate || !$usercanvalidate) {
		accessforbidden('Not enough permissions');
	}
}

$error = 0;
$errmsg = '';

// Source invoice: access (entity, thirdparty), validated/paid and not a credit note.
$esrcinvoice = easyback_fetch_source_invoice($db, $user, $isSupplier, $facid, $errmsg);
if (!$esrcinvoice) {
	accessforbidden(dol_escape_htmltag($errmsg));
}

$epayments = array();
$alreadyreturned = 0;
$emonto_devolver = 0;

$needwarehouse = 0;
$ewarehouse_array = array();

$formproduct = new FormProduct($db);

/**
 * Helper: create the credit note (draft) from the source invoice + marked lines.
 *
 * @param DoliDB  $db            Database handler
 * @param User    $user          User
 * @param bool    $isSupplier    True for supplier invoice, false for customer
 * @param int     $facid         Source invoice id
 * @param int[]   $lineids_arr   Selected line ids
 * @param mixed[] $qtys_arr      Quantities keyed by line id
 * @param string  $posterminal   POS terminal (empty for a backoffice return)
 * @param string  $errmsg        Output error message
 * @return int                   Credit note id or 0 on error
 */
function easyback_create_credit_note($db, $user, $isSupplier, $facid, $lineids_arr, $qtys_arr, $posterminal, &$errmsg)
{
	global $langs;

	$cnid = 0;

	if ($isSupplier) {
		$src = new FactureFournisseur($db);
		if ($src->fetch($facid) <= 0) {
			$errmsg = $src->error;
			return 0;
		}
		$src->fetch_lines();

		$cn = new FactureFournisseur($db);
		$cn->socid = $src->socid;
		// ref_supplier must be unique for (ref_supplier, fk_soc, entity). We can't use '' for a credit
		// note when the source invoice also has an empty ref_supplier (uk_facture_fourn_ref_supplier).
		// The native flow uses "CopyOf". We build a readable ref based on the unique source invoice ref,
		// with an incremental suffix so revisits don't collide with a previous credit note.
		$cn->ref_supplier = easyback_make_unique_ref_supplier($db, $langs->trans('EasyBackDevolucion').' '.$src->ref, $src->socid);
		$cn->type = FactureFournisseur::TYPE_CREDIT_NOTE;
		$cn->fk_facture_source = $src->id;
		$cn->cond_reglement_id = 0;
		$cn->mode_reglement_id = $src->mode_reglement_id;
		$cn->fk_account = $src->fk_account;
		$cn->date = dol_now();
		// Keep the same currency/rate as the source invoice so the bank reversal
		// respects the invoice rate (multicurrency) when registering the payment.
		$cn->multicurrency_code = $src->multicurrency_code;
		$cn->multicurrency_tx = $src->multicurrency_tx;

		$cnid = $cn->create($user);
		if ($cnid < 0) {
			$errmsg = $cn->error;
			return 0;
		}
		$cn->id = $cnid;

		foreach ($lineids_arr as $lineid) {
			$lineid = (int) $lineid;
			$qty = (isset($qtys_arr[$lineid]) && $qtys_arr[$lineid] !== '') ? price2num($qtys_arr[$lineid], 'MS') : 1;
			if ($qty <= 0) { continue; }

			$sl = new SupplierInvoiceLine($db);
			if ($sl->fetch($lineid) <= 0) { continue; }

			$cn->addline(
				$sl->desc,
				$sl->subprice,
				price2num($sl->tva_tx, 'MS'),
				$sl->localtax1_tx,
				$sl->localtax2_tx,
				$qty,
				$sl->fk_product,
				$sl->remise_percent,
				$sl->date_start,
				$sl->date_end,
				$sl->fk_code_ventilation,
				$sl->info_bits,
				'HT',
				$sl->product_type,
				-1,
				0,
				array(),
				$sl->fk_unit,
				$sl->origin_id,
				0,
				$sl->ref_supplier,
				$sl->special_code,
				$sl->fk_parent_line,
				0                       // fk_remise_except: the discount stays linked to the source invoice only
			);
		}

		$cn->fetch($cnid);
		$cn->add_object_linked('invoice_supplier', $src->id);
		$cn->fetch($cnid);
	} else {
		$src = new Facture($db);
		if ($src->fetch($facid) <= 0) {
			$errmsg = $src->error;
			return 0;
		}
		$src->fetch_lines();

		$cn = new Facture($db);
		$cn->socid = $src->socid;
		$cn->type = Facture::TYPE_CREDIT_NOTE;
		$cn->fk_facture_source = $src->id;
		$cn->date = dol_now();
		// A return made from the TakePOS is marked with its origin (module + terminal)
		// so the POS cash close can attribute it to that terminal. Returns made from the
		// backoffice (partial or total) stay unmarked (they are not part of the POS transactions).
		if (!empty($posterminal)) {
			$cn->module_source = 'easyback';
			$cn->pos_source = $posterminal;
		}
		$cn->cond_reglement_id = 0;
		$cn->mode_reglement_id = $src->mode_reglement_id;
		$cn->fk_account = $src->fk_account;
		// Keep the same currency/rate as the source invoice so the bank reversal
		// respects the invoice rate (multicurrency) when registering the payment.
		$cn->multicurrency_code = $src->multicurrency_code;
		$cn->multicurrency_tx = $src->multicurrency_tx;

		$cnid = $cn->create($user);
		if ($cnid < 0) {
			$errmsg = $cn->error;
			return 0;
		}
		$cn->id = $cnid;

		foreach ($lineids_arr as $lineid) {
			$lineid = (int) $lineid;
			$qty = (isset($qtys_arr[$lineid]) && $qtys_arr[$lineid] !== '') ? price2num($qtys_arr[$lineid], 'MS') : 1;
			if ($qty <= 0) { continue; }

			$sl = new FactureLigne($db);
			if ($sl->fetch($lineid) <= 0) { continue; }

			$cn->addline(
				$sl->desc,
				$sl->subprice,
				$qty,
				price2num($sl->tva_tx, 'MS'),
				$sl->localtax1_tx,
				$sl->localtax2_tx,
				$sl->fk_product,
				$sl->remise_percent,
				$sl->date_start,
				$sl->date_end,
				$sl->fk_code_ventilation,
				$sl->info_bits,
				0,                      // fk_remise_except: the discount stays linked to the source invoice only
				'HT',
				0,
				$sl->product_type,
				-1,
				$sl->special_code,
				$sl->origin,
				$sl->origin_id,
				$sl->fk_parent_line,
				null,
				0,                      // pa_ht
				'',                     // label (deprecated)
				array(),                // array_options
				100,                    // situation_percent
				0,                      // fk_prev_id
				$sl->fk_unit,           // fk_unit
				$sl->pu_ht_devise,      // pu_ht_devise
				$sl->ref_ext            // ref_ext
			);
		}

		$cn->fetch($cnid);
		$cn->add_object_linked('facture', $src->id);
		$cn->fetch($cnid);
	}

	return $cnid;
}

/**
 * Build a unique ref_supplier for a supplier credit note, appending an
 * incremental suffix when the base value already exists for the same supplier
 * (to respect the uk_facture_fourn_ref_supplier unique index).
 *
 * @param DoliDB $db       Database handler
 * @param string $base     Base reference (e.g. "Devolución <ref>")
 * @param int    $socid    Supplier id
 * @return string          Unique ref_supplier
 */
function easyback_make_unique_ref_supplier($db, $base, $socid)
{
	$base = trim($base);
	$candidate = $base;
	$i = 1;
	while (true) {
		$sql = "SELECT COUNT(*) as n FROM ".$db->prefix()."facture_fourn WHERE ref_supplier = '".$db->escape($candidate)."' AND fk_soc = ".((int) $socid);
		$resql = $db->query($sql);
		$n = 0;
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$n = (int) $obj->n;
		}
		if ($n == 0) {
			return $candidate;
		}
		$i++;
		$candidate = $base.' ('.($i - 1).')';
	}
}

/**
 * Key used to match a credit note line with the source line it was copied from.
 *
 * @param object $line Invoice or credit note line
 * @return string      Product, unit price and description
 */
function easyback_line_key($line)
{
	return ((int) $line->fk_product).'|'.price2num(abs((float) $line->subprice), 'MU').'|'.trim((string) $line->desc);
}

/**
 * Quantities still returnable per line of the source invoice: the invoiced
 * quantity minus what its validated credit notes already returned. The credit
 * note lines are matched to the source lines by product, unit price and
 * description (they are copied from them), in the order of the lines.
 *
 * @param DoliDB $db          Database handler
 * @param bool   $isSupplier  True for supplier invoice, false for customer
 * @param int    $facid       Source invoice id
 * @return array<int,float>   Map lineid => returnable quantity (only lines > 0)
 */
function easyback_get_returnable_lines_qty($db, $isSupplier, $facid)
{
	$src = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	if ($src->fetch($facid) <= 0) {
		return array();
	}
	$src->fetch_lines();

	$returned = array();
	foreach (easyback_get_creditnotes($db, $isSupplier, $facid, false) as $cnid) {
		$cn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
		if ($cn->fetch($cnid) <= 0) {
			continue;
		}
		$cn->fetch_lines();
		foreach ($cn->lines as $line) {
			$key = easyback_line_key($line);
			$returned[$key] = (isset($returned[$key]) ? $returned[$key] : 0) + abs((float) $line->qty);
		}
	}

	$tmp = array();
	foreach ($src->lines as $line) {
		$qty = (float) price2num($line->qty, 'MS');
		if ($qty <= 0) {
			continue;
		}
		$key = easyback_line_key($line);
		if (!empty($returned[$key])) {
			$used = min($qty, $returned[$key]);
			$returned[$key] -= $used;
			$qty = (float) price2num($qty - $used, 'MS');
		}
		if ($qty > 0) {
			$tmp[(int) $line->id] = $qty;
		}
	}

	return $tmp;
}

/**
 * Print the form asking for the warehouse that receives the returned stock. It
 * carries the whole request so the retry returns the same lines from the same origin.
 *
 * @param FormProduct        $formproduct  Form helper
 * @param string             $emode        'partial' or 'total'
 * @param string             $element      Invoice element
 * @param int                $facid        Source invoice id
 * @param array<int,float>   $elines_qty   Map lineid => quantity to return
 * @param string             $epopup       Popup mode
 * @param string             $eposterminal POS terminal
 * @param int                $enoreturnstock 1 if the POS terminal does not move stock
 * @return void
 */
function easyback_print_warehouse_form($formproduct, $emode, $element, $facid, $elines_qty, $epopup, $eposterminal, $enoreturnstock)
{
	global $langs;

	print '<h4>'.$langs->trans('EasyBackSelectWarehouse').'</h4>';
	print '<form method="post" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.currentToken().'">';
	print '<input type="hidden" name="action" value="validate">';
	print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($emode).'">';
	print '<input type="hidden" name="element" value="'.dol_escape_htmltag($element).'">';
	print '<input type="hidden" name="facid" value="'.(int) $facid.'">';
	print '<input type="hidden" name="popup" value="'.dol_escape_htmltag($epopup).'">';
	if ($emode == 'partial') {
		foreach ($elines_qty as $elineid => $eqty) {
			print '<input type="hidden" name="lineid[]" value="'.(int) $elineid.'">';
			print '<input type="hidden" name="qty['.(int) $elineid.']" value="'.dol_escape_htmltag((string) $eqty).'">';
		}
	}
	print $formproduct->selectWarehouses(0, 'idwarehouse', '', 1, 0, '', 0);
	print '<br><br>';
	print '<button class="butAction" type="submit">'.$langs->trans('EasyBackValidarAbono').'</button>';
	print '</form>';
}

// Lines to return: what is still returnable of the whole invoice (total) or of
// the selected lines (partial). Previous returns are discounted, so each return
// gets its own credit note and an invoice is never returned twice.
$ereturnable = easyback_get_returnable_lines_qty($db, $isSupplier, $facid);
$elines_qty = array();
if ($ispartial) {
	if (is_array($elineids)) {
		foreach ($elineids as $elineid) {
			$elineid = (int) $elineid;
			if (!isset($ereturnable[$elineid])) {
				continue; // Not a line of this invoice, or already returned in full.
			}
			$eq = (isset($eqtys[$elineid]) && $eqtys[$elineid] !== '') ? price2num($eqtys[$elineid], 'MS') : $ereturnable[$elineid];
			if ($eq <= 0) {
				continue;
			}
			if ($eq > $ereturnable[$elineid]) {
				$eq = $ereturnable[$elineid]; // Never more than what is left to return.
			}
			$elines_qty[$elineid] = $eq;
		}
	}
} else {
	$elines_qty = $ereturnable;
}
$lineids_arr = array_keys($elines_qty);
$qtys_arr = $elines_qty;

$creditnote_id = 0;
if (count($ereturnable) <= 0) {
	// Nothing left to return: point to the last credit note of the invoice.
	$evalidated = easyback_get_creditnotes($db, $isSupplier, $facid, false);
	if (count($evalidated) > 0) {
		$alreadyreturned = end($evalidated);
	} else {
		$error++;
		$errmsg = $langs->trans('ErrorObjectMustHaveLinesToBeValidated', '');
	}
} elseif (count($lineids_arr) <= 0) {
	$error++;
	$errmsg = $langs->trans((is_array($elineids) && count($elineids) > 0) ? 'EasyBackErrorLineasDevueltas' : 'EasyBackErrorSinLineas');
}

// Transaction that keeps the source invoice locked from the creation of the credit note
// until its validation, so two simultaneous returns (two tabs, double click) cannot
// return the same quantities twice.
$elocktx = false;
if (!$error && !$alreadyreturned) {
	$db->begin();
	$elocktx = true;
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX.($isSupplier ? 'facture_fourn' : 'facture')." WHERE rowid = ".((int) $facid)." FOR UPDATE");
	if (!$resql) {
		$error++;
		$errmsg = $db->lasterror();
	} else {
		// Recompute under the lock: another return may have been validated meanwhile.
		$elockedqty = easyback_get_returnable_lines_qty($db, $isSupplier, $facid);
		foreach ($qtys_arr as $elineid => $eq) {
			if (!isset($elockedqty[$elineid])) {
				unset($qtys_arr[$elineid]);
			} elseif ($eq > $elockedqty[$elineid]) {
				$qtys_arr[$elineid] = $elockedqty[$elineid];
			}
		}
		$lineids_arr = array_keys($qtys_arr);
		$elines_qty = $qtys_arr;
		if (count($lineids_arr) <= 0) {
			$error++;
			$errmsg = $langs->trans('EasyBackErrorLineasDevueltas');
		}
	}
}
if (!$error && !$alreadyreturned) {
	// A draft left by an unfinished attempt is replaced by the credit note of this return.
	foreach (easyback_get_creditnotes($db, $isSupplier, $facid, true) as $edraftid) {
		$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
		if ($ecn->fetch($edraftid) > 0 && $ecn->delete($user) < 0) {
			$error++;
			$errmsg = $ecn->error;
			break;
		}
	}
	if (!$error) {
		$creditnote_id = easyback_create_credit_note($db, $user, $isSupplier, $facid, $lineids_arr, $qtys_arr, $eposterminal, $errmsg);
		if ($creditnote_id <= 0) {
			$error++;
		}
	}
}
if ($elocktx && $error) {
	$creditnote_id = 0;
	$db->rollback();
	$elocktx = false;
}

// Determine stock / warehouse needs
if ($creditnote_id > 0 && !$error && !$alreadyreturned && getDolGlobalString('STOCK_CALCULATE_ON_BILL') && $estockallowed) {
	$esrc = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	$esrc->fetch($facid);
	$hasProducts = method_exists($esrc, 'hasProductsOrServices') ? $esrc->hasProductsOrServices(2) : 0;
	if ($hasProducts) {
		$ewarehouse = new Entrepot($db);
		$ewarehouse_array = $ewarehouse->list_array();
		if (count($ewarehouse_array) == 1) {
			$idwarehouse = (int) key($ewarehouse_array); // single warehouse: use it directly
		} elseif (count($ewarehouse_array) > 1 && empty($idwarehouse)) {
			$needwarehouse = 1; // several warehouses: ask (unless the caller, e.g. the POS, gave one)
		}
	}
}

// Validate action
if ($action == 'validate' && $creditnote_id > 0 && !$error && !$alreadyreturned) {
	if ($needwarehouse && $idwarehouse <= 0) {
		// The stock must be returned, so a warehouse is mandatory.
		$error++;
		$errmsg = $langs->trans('EasyBackErrorAlmacen');
	} else {
		$db->begin();
		$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
		$ecn->fetch($creditnote_id);
		// Only move stock if EASYBACK_REINTEGRO_STOCK is on. validate() moves stock
		// only when $idwarehouse > 0, so pass 0 to disable stock movement.
		$ewh = $estockallowed ? $idwarehouse : 0;
		$eresult = $ecn->validate($user, '', $ewh);
		if ($eresult < 0) {
			$error++;
			$errmsg = $ecn->error;
			$db->rollback();
			if ($elocktx) {
				// A nested rollback does not undo anything: roll back the whole return
				// (credit note + any stock movement) so no partial state is committed.
				$db->rollback();
				$elocktx = false;
				$creditnote_id = 0;
			}
		} else {
			$db->commit();
			$needwarehouse = 0; // Once validated, no longer ask for warehouse
		}
	}
}

// Auto-validate if not requested warehouse
if ($creditnote_id > 0 && !$error && !$alreadyreturned && getDolGlobalInt('EASYBACK_AUTO_VALIDAR') && !$needwarehouse) {
	$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	$ecn->fetch($creditnote_id);
	if ($ecn->status == 0) { // draft only
		$db->begin();
		$ewh = $estockallowed ? $idwarehouse : 0;
		$eresult = $ecn->validate($user, '', $ewh);
		if ($eresult < 0) {
			$error++;
			$errmsg = $ecn->error;
			$db->rollback();
			if ($elocktx) {
				// A nested rollback does not undo anything: roll back the whole return
				// (credit note + any stock movement) so no partial state is committed.
				$db->rollback();
				$elocktx = false;
				$creditnote_id = 0;
			}
		} else {
			$db->commit();
		}
	}
}

// End of the locked transaction of the return.
if ($elocktx) {
	$db->commit();
	$elocktx = false;
}

// Fetch credit note lines/total and the payments of the source invoice.
$ecnlines = array();
$ecurrencycode = $conf->currency;
if ($creditnote_id > 0 && !$error && !$alreadyreturned) {
	$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	$ecn->fetch($creditnote_id);
	$ecn->fetch_lines();
	$ecnlines = $ecn->lines;
	$ecurrencycode = $ecn->multicurrency_code ? $ecn->multicurrency_code : $conf->currency;

	$esrc = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	$esrc->fetch($facid);
	// mode = 1 => real payments only (not credit notes/discounts/deposits),
	// listed in the invoice currency so the reversal keeps the invoice rate.
	$epayments = $esrc->getListOfPayments('', 1, 1);

	// Money to refund, in the credit note (invoice) currency: the credit note of
	// this return, never more than what was collected and not refunded yet.
	$emonto_devolver = !empty($ecn->multicurrency_total_ttc) ? abs((float) $ecn->multicurrency_total_ttc) : abs((float) $ecn->total_ttc);
}

$etotal_pagos = 0;
foreach ($epayments as $epayment) {
	$etotal_pagos += abs((float) $epayment['amount']);
}
if ($creditnote_id > 0) {
	$erefunded = 0;
	foreach (easyback_get_creditnotes($db, $isSupplier, $facid, false) as $ecnid) {
		$eprev = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
		if ($eprev->fetch($ecnid) > 0) {
			$erefunded += abs((float) $eprev->getSommePaiement(1));
		}
	}
	$emonto_devolver = (float) price2num(min($emonto_devolver, max(0, $etotal_pagos - $erefunded)), 'MT');
}

// The credit note is validated (stock already returned) but its money is not
// refunded until the operator processes it: closing now must be confirmed.
$epending = (!$error && !$alreadyreturned && !$needwarehouse && $creditnote_id > 0 && $ecn->status > 0 && count($epayments) > 0 && $emonto_devolver > 0);

llxHeader('', $langs->trans('EasyBackProcesarDevolucion'));

print '<div style="padding:10px;">';
print '<script>
// While the refund is pending, every way of closing the popup (the "Cerrar"
// buttons here, the X/ESC of the invoice card dialog and of the POS colorbox,
// which call easybackCanClose()) shows the warning instead of closing.
var easybackPending = '.($epending ? 'true' : 'false').';
function easybackCanClose() {
    if (!easybackPending) { return true; }
    var w = document.getElementById("easyback-close-warning");
    if (w) { w.style.display = "block"; w.scrollIntoView(); }
    return false;
}
function easybackForceClose() {
    easybackPending = false;
    easybackReloadParent();
}
window.addEventListener("beforeunload", function(e) {
    if (easybackPending) { e.preventDefault(); e.returnValue = ""; }
});
// Close/reload the parent page: supports the invoice card (jQuery UI dialog) and
// the TakePOS (colorbox iframe).
function easybackCloseParent() {
    if (!easybackCanClose()) { return; }
    if (parent.$ && parent.$.colorbox) {
        parent.$.colorbox.close();
    } else if (parent.jQuery && parent.jQuery("#idfordialogeasyback").length) {
        parent.jQuery("#idfordialogeasyback").dialog("close");
    }
}
function easybackReloadParent() {
    if (!easybackCanClose()) { return; }
    if (parent.$ && parent.$.colorbox) {
        parent.$.colorbox.close();
        if (typeof parent.Refresh === "function") { parent.Refresh(); }
    } else if (parent.jQuery && parent.jQuery("#idfordialogeasyback").length) {
        parent.location.reload();
    }
}
</script>';
print '<h3>'.($ispartial ? $langs->trans('EasyBackDevolucion') : $langs->trans('EasyBackDevolucionTotal')).'</h3>';

if ($alreadyreturned) {
	$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	$ecn->fetch($alreadyreturned);
	$enotaUrl = DOL_URL_ROOT.($isSupplier ? '/fourn/facture/card.php' : '/compta/facture/card.php').'?facid='.((int) $alreadyreturned);

	print '<div class="warning">'.$langs->trans('EasyBackYaDevuelta', $ecn->ref).'</div>';
	print '<br>';
	if ($epopup != 'colorbox') {
		print '<a class="butAction" href="#" onclick="parent.window.location.href = \''.dol_escape_js($enotaUrl).'\'; return false;">'.$langs->trans('EasyBackVerNota').'</a> &nbsp; ';
	}
	print '<a class="butActionRefused" href="#" onclick="easybackCloseParent(); return false;">'.$langs->trans('EasyBackCerrar').'</a>';
} elseif ($error) {
	print '<div class="error">'.$langs->trans('Error').' : '.dol_escape_htmltag($errmsg).'</div>';
	if ($needwarehouse) {
		// Show the warehouse form again so the operator can pick one and retry.
		easyback_print_warehouse_form($formproduct, $emode, $element, $facid, $elines_qty, $epopup, $eposterminal, $enoreturnstock);
	}
	print '<a class="butActionRefused" href="#" onclick="easybackCloseParent(); return false;">'.$langs->trans('EasyBackCerrar').'</a>';
} else {
	$ecnref = '';
	if ($creditnote_id > 0) {
		$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
		$ecn->fetch($creditnote_id);
		$ecnref = $ecn->ref;
	}
	// URL of the created credit note.
	$enotaUrl = DOL_URL_ROOT.($isSupplier ? '/fourn/facture/card.php' : '/compta/facture/card.php').'?facid='.((int) $creditnote_id);

	print '<div class="ok">'.$langs->trans('EasyBackAbonoCreado', $ecnref).'</div>';
	print '<br>';

	if ($epending) {
		print '<div id="easyback-close-warning" class="warning" style="display:none;">';
		// trans() encodes its parameters itself.
		print $langs->trans('EasyBackAvisoSinReembolso', $ecnref, dol_html_entity_decode(price($emonto_devolver, 0, '', 1, -1, -1, $ecurrencycode), ENT_QUOTES | ENT_HTML5));
		print '<br><br>';
		print '<a class="butAction" href="#" onclick="document.getElementById(\'easyback-close-warning\').style.display = \'none\'; return false;">'.$langs->trans('EasyBackVolver').'</a> &nbsp; ';
		print '<a class="butActionDelete" href="#" onclick="easybackForceClose(); return false;">'.$langs->trans('EasyBackCerrarSinReembolso').'</a>';
		print '</div>';
	}

	// If warehouse confirmation is needed
	if ($needwarehouse) {
		easyback_print_warehouse_form($formproduct, $emode, $element, $facid, $elines_qty, $epopup, $eposterminal, $enoreturnstock);

		print '</div>';
		llxFooter();
		$db->close();
		exit;
	}

	// Summary of the returned lines (read only).
	print '<h4>'.$langs->trans('EasyBackLineasDevueltas').'</h4>';
	if (count($ecnlines) > 0) {
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th>'.$langs->trans('Description').'</th>';
		print '<th class="right">'.$langs->trans('Qty').'</th>';
		print '<th class="right">'.$langs->trans('TotalTTC').'</th>';
		print '</tr>';
		foreach ($ecnlines as $eline) {
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag((string) $eline->desc).'</td>';
			print '<td class="right">'.price2num($eline->qty, 'MS').'</td>';
			print '<td class="right">'.price(abs((float) $eline->total_ttc), 0, '', 1, -1, -1, $ecurrencycode).'</td>';
			print '</tr>';
		}
		print '</table>';
	}

	// Payments of the source invoice.
	print '<h4>'.$langs->trans('EasyBackPagosRealizados').'</h4>';

	if (count($epayments) > 0) {
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		if ($ispartial) {
			print '<th style="width:20px;"></th>';
		}
		print '<th>'.$langs->trans('Payment').'</th>';
		print '<th>'.$langs->trans('BankAccount').'</th>';
		print '<th class="right">'.$langs->trans('Amount').'</th>';
		print '<th class="right">'.$langs->trans('Date').'</th>';
		print '</tr>';
		foreach ($epayments as $epayment) {
			$eamount = abs((float) $epayment['amount']);
			$epaytype = $epayment['type'];
			// Resolve the bank account where this channel was originally paid, so
			// the reversal registers in the same bank (respects its currency).
			$ebanklabel = '';
			$ebankacctid = 0;
			if (!empty($epayment['fk_bank_line'])) {
				$ebankline = new AccountLine($db);
				if ($ebankline->fetch((int) $epayment['fk_bank_line']) > 0 && !empty($ebankline->fk_account)) {
					$ebankacctid = (int) $ebankline->fk_account;
					$ebankaccount = new Account($db);
					if ($ebankaccount->fetch($ebankacctid) > 0) {
						$ebanklabel = $ebankaccount->label;
					}
				}
			}
			print '<tr class="oddeven">';
			if ($ispartial) {
				print '<td><input type="checkbox" class="easyback-pay" name="easyback_payment[]" value="'.dol_escape_htmltag($epaytype).'" data-amount="'.$eamount.'" data-type="'.dol_escape_htmltag($epaytype).'" data-account="'.(int) $ebankacctid.'"></td>';
			}
			print '<td>'.dol_escape_htmltag(easyback_payment_label($db, $epaytype)).'</td>';
			print '<td>'.($ebanklabel ? dol_escape_htmltag($ebanklabel) : '<span class="opacitymedium">-</span>').'</td>';
			print '<td class="right">'.price($eamount).' '.$ecurrencycode.'</td>';
			print '<td class="right">'.dol_print_date($db->jdate($epayment['date']), '%d/%m/%Y').'</td>';
			print '</tr>';
		}
		print '</table>';

		print '<div style="margin:12px 0;">';
		if ($ispartial) {
			print $langs->trans('EasyBackMontoDevolver').' : <b>'.price($emonto_devolver, 0, '', 1, -1, -1, $ecurrencycode).'</b> &nbsp; ';
			print $langs->trans('EasyBackReversoTot').' : <b id="easyback-sel-total">0 '.$ecurrencycode.'</b>';
		} else {
			print $langs->trans('EasyBackMontoDevolver').' : <b>'.price($emonto_devolver, 0, '', 1, -1, -1, $ecurrencycode).'</b>';
		}
		print '</div>';

		if ($epopup == 'colorbox') {
			print '<div class="error" id="easyback-error-box" style="display:none;"></div>';
		}

		$eajaxurl = dol_buildpath('/easyback/return_ajax.php', 1);
		print '<button class="butAction" id="easyback-reverse-btn" onclick="EasyBackReverse()">'.($ispartial ? $langs->trans('EasyBackProcesarReverso') : $langs->trans('EasyBackProcesarDevolucion')).'</button> &nbsp; ';
		// From the POS we must not navigate the parent page away from the POS.
		if ($epopup != 'colorbox') {
			print '<a class="butAction" href="#" onclick="parent.window.location.href = \''.dol_escape_js($enotaUrl).'\'; return false;">'.$langs->trans('EasyBackVerNota').'</a> &nbsp; ';
		}
		print '<a class="butActionRefused" href="#" onclick="easybackReloadParent(); return false;">'.$langs->trans('EasyBackCerrar').'</a>';

		$esuccessmsg = dol_escape_js($langs->trans('EasyBackExito'));
		if ($epopup == 'colorbox') {
			// POS: show the error inside the popup (the colorbox overlay would hide
			// a dialog of the parent) and notify on the POS after closing.
			$eonerror = '$("#easyback-error-box").text(resp.error).show();';
			$eonsuccess = 'parent.EasyBackPosNotify("'.dol_escape_js($langs->trans('EasyBackDevolucionTotal')).'", "'.$esuccessmsg.'");';
		} else {
			$eonerror = 'parent.easybackNotification(easybackAviso, resp.error);';
			$eonsuccess = 'parent.easybackNotification(easybackProcesar, "'.$esuccessmsg.'", { url: easybackNotaUrl });';
		}

		if ($ispartial) {
			// Partial: the operator marks which payment channels to reverse; the
			// equivalent money is prorated across them by their original weight.
			print '<script>
var easybackAjaxUrl = "'.dol_escape_js($eajaxurl).'";
var easybackCreditnoteId = "'.(int) $creditnote_id.'";
var easybackFacid = "'.(int) $facid.'";
var easybackElement = "'.dol_escape_js($isSupplier ? 'facture_fourn' : 'facture').'";
var easybackToken = "'.dol_escape_js(currentToken()).'";
var easybackMonto = "'.price2num($emonto_devolver, 'MT').'";
var easybackCurrency = "'.dol_escape_js($ecurrencycode).'";
var easybackNotaUrl = "'.dol_escape_js($enotaUrl).'";
var easybackAviso = "'.dol_escape_js($langs->trans('EasyBackAviso')).'";
var easybackProcesar = "'.dol_escape_js($langs->trans('EasyBackDevolucion')).'";
function EasyBackUpdateSelTotal() {
    var total = 0;
    if ($("input.easyback-pay:checked").length > 0) { total = parseFloat(easybackMonto) || 0; }
    $("#easyback-sel-total").text(total.toFixed(2) + " " + easybackCurrency);
}
$(function() {
    $("input.easyback-pay").on("change", EasyBackUpdateSelTotal);
    EasyBackUpdateSelTotal();
});
function EasyBackReverse() {
    var payments = [];
    $("input.easyback-pay:checked").each(function() {
        payments.push({ type: $(this).attr("data-type"), weight: parseFloat($(this).attr("data-amount")), account: parseInt($(this).attr("data-account")) || 0 });
    });
    if (!payments.length) { parent.easybackNotification(easybackAviso, "'.dol_escape_js($langs->trans('EasyBackErrorMarcaPago')).'"); return; }
    var edata = { token: easybackToken, creditnote_id: easybackCreditnoteId, facid: easybackFacid, element: easybackElement, payments: JSON.stringify(payments) };
    $("#easyback-reverse-btn").prop("disabled", true);
    $.post(easybackAjaxUrl, edata, function(resp) {
        if (resp.error) {
            $("#easyback-reverse-btn").prop("disabled", false);
            '.$eonerror.'
        } else {
            easybackPending = false;
            '.$eonsuccess.'
        }
    }, "json");
}
</script>';
		} else {
			print '<script>
var easybackAjaxUrl = "'.dol_escape_js($eajaxurl).'";
var easybackCreditnoteId = "'.(int) $creditnote_id.'";
var easybackFacid = "'.(int) $facid.'";
var easybackElement = "'.dol_escape_js($isSupplier ? 'facture_fourn' : 'facture').'";
var easybackToken = "'.dol_escape_js(currentToken()).'";
var easybackCurrency = "'.dol_escape_js($ecurrencycode).'";
var easybackNotaUrl = "'.dol_escape_js($enotaUrl).'";
var easybackAviso = "'.dol_escape_js($langs->trans('EasyBackAviso')).'";
var easybackProcesar = "'.dol_escape_js($langs->trans('EasyBackDevolucionTotal')).'";
function EasyBackReverse() {
    var edata = { token: easybackToken, creditnote_id: easybackCreditnoteId, facid: easybackFacid, element: easybackElement };
    $("#easyback-reverse-btn").prop("disabled", true);
    $.post(easybackAjaxUrl, edata, function(resp) {
        if (resp.error) {
            $("#easyback-reverse-btn").prop("disabled", false);
            '.$eonerror.'
        } else {
            easybackPending = false;
            '.$eonsuccess.'
        }
    }, "json");
}
</script>';
		}
	} else {
		print '<div class="opacitymedium">'.($ispartial ? $langs->trans('EasyBackSinPagos') : $langs->trans('EasyBackSinPagosTotal')).'</div>';
		if ($epopup != 'colorbox') {
			print '<a class="butAction" href="#" onclick="parent.window.location.href = \''.dol_escape_js($enotaUrl).'\'; return false;">'.$langs->trans('EasyBackVerNota').'</a> &nbsp; ';
		}
		print '<a class="butActionRefused" href="#" onclick="easybackReloadParent(); return false;">'.$langs->trans('EasyBackCerrar').'</a>';
	}
}

print '</div>';

llxFooter();
$db->close();

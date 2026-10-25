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
 * \file    easyback/return_ajax.php
 * \ingroup easyback
 * \brief   AJAX endpoint: register the bank reversal (refund) of a return.
 *          - PARTIAL ('payments' provided): the equivalent money is prorated
 *            across the marked channels by their original weight.
 *          - TOTAL (no 'payments'): every payment of the source invoice is
 *            reversed in full, in its own channel and bank account.
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

require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/easyback/lib/easyback.lib.php');

header('Content-Type: application/json; charset=UTF-8');

/**
 * Print a JSON error, roll back any open transaction and stop.
 *
 * @param string $msg Error message
 * @return never
 */
function easyback_ajax_fail($msg)
{
	global $db;
	while ($db->transaction_opened > 0) {
		$db->rollback();
	}
	print json_encode(array('error' => $msg));
	$db->close();
	exit;
}

$langs->loadLangs(array('easyback@easyback', 'payments', 'banks', 'errors'));

global $user, $conf;

$token = GETPOST('token', 'aZ09');
$creditnote_id = GETPOSTINT('creditnote_id');
$facid = GETPOSTINT('facid');
$element = GETPOST('element', 'alpha');
$epaymentsjson = GETPOST('payments', 'none');              // JSON array of the marked channels {type, account} (partial)

if (!isModEnabled('easyback')) {
	print json_encode(array('error' => 'Module not enabled'));
	exit;
}

// CSRF: this endpoint registers bank movements, so the token is always mandatory.
if (empty($token) || $token != currentToken()) {
	print json_encode(array('error' => $langs->transnoentities('EasyBackErrorToken')));
	exit;
}

$isSupplier = ($element == 'facture_fourn' || $element == 'invoice_supplier');

// Permissions: replicate the native card.php logic (create + validate)
if ($isSupplier) {
	$usercancreate = ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
	$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
		|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate')));
	if (!$usercancreate || !$usercanvalidate) {
		print json_encode(array('error' => $langs->transnoentities('NotEnoughPermissions')));
		exit;
	}
} else {
	$usercancreate = $user->hasRight('facture', 'creer');
	$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
		|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'validate')));
	if (!$usercancreate || !$usercanvalidate) {
		print json_encode(array('error' => $langs->transnoentities('NotEnoughPermissions')));
		exit;
	}
}

if ($creditnote_id <= 0 || $facid <= 0) {
	easyback_ajax_fail($langs->transnoentities('ErrorFieldRequired', 'creditnote_id'));
}

// Source invoice: access (entity, thirdparty), validated/paid and not a credit note.
$errmsg = '';
$esrc = easyback_fetch_source_invoice($db, $user, $isSupplier, $facid, $errmsg);
if (!$esrc) {
	easyback_ajax_fail($errmsg);
}

// Everything below runs in one transaction with the source invoice and the credit note
// locked, so two simultaneous requests (double click, two tabs, retry) cannot refund the
// same money twice: the second one waits and then sees the first refund.
$db->begin();
$etable = MAIN_DB_PREFIX.($isSupplier ? 'facture_fourn' : 'facture');
if (!$db->query("SELECT rowid FROM ".$etable." WHERE rowid IN (".((int) $facid).", ".((int) $creditnote_id).") FOR UPDATE")) {
	easyback_ajax_fail($db->lasterror());
}

// Load the credit note to get its currency, rate and remaining amount.
$ecn = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
if ($ecn->fetch($creditnote_id) <= 0) {
	easyback_ajax_fail($ecn->error ? $ecn->error : $langs->transnoentities('ErrorRecordNotFound'));
}
// Only a validated credit note of this very invoice can be refunded.
if ((int) $ecn->type != 2 || (int) $ecn->fk_facture_source != $facid || (int) $ecn->status <= 0 || (int) $ecn->entity != (int) $esrc->entity) {
	easyback_ajax_fail($langs->transnoentities('ErrorBadParameters'));
}

// Real payments of the source invoice, in the invoice currency (mode = 1: not
// credit notes/discounts/deposits).
$epayments = $esrc->getListOfPayments('', 1, 1);
if (!is_array($epayments)) {
	$epayments = array();
}

$einvoicecurrency = $ecn->multicurrency_code ? $ecn->multicurrency_code : $conf->currency;
$erate = !empty($ecn->multicurrency_tx) ? (float) $ecn->multicurrency_tx : 1;
$ecn_total = abs(!empty($ecn->multicurrency_total_ttc) ? (float) $ecn->multicurrency_total_ttc : (float) $ecn->total_ttc);
$ealready = abs((float) $ecn->getSommePaiement(1));

// What is left to refund: the unpaid part of this credit note, never more than
// what the invoice collected minus what its credit notes already refunded.
$ecollected = 0;
foreach ($epayments as $epayment) {
	$ecollected += abs((float) $epayment['amount']);
}
$erefunded = 0;
foreach (easyback_get_creditnotes($db, $isSupplier, $facid, false) as $ecnid) {
	$eprev = $isSupplier ? new FactureFournisseur($db) : new Facture($db);
	if ($eprev->fetch($ecnid) > 0) {
		$erefunded += abs((float) $eprev->getSommePaiement(1));
	}
}
$eremaining = price2num(min($ecn_total - $ealready, $ecollected - $erefunded), 'MT');

/**
 * Resolve the bank account to use for a reversal of one channel.
 *
 * Priority:
 *   1. The bank account where this channel was originally paid.
 *   2. The bank account of the credit note.
 * The account must be open and in the main currency or in the credit note currency.
 * No other account is guessed: refunding from an arbitrary account would unbalance it.
 *
 * @param DoliDB $db              Database handler
 * @param int    $preferredacct   Preferred bank account id (original channel), or 0
 * @param string $invoicecurrency Credit note currency
 * @param int    $cnaccount       Bank account of the credit note
 * @return int                    Bank account id, 0 if none is valid
 */
function easyback_resolve_bank_account($db, $preferredacct, $invoicecurrency, $cnaccount)
{
	foreach (array((int) $preferredacct, (int) $cnaccount) as $accountid) {
		if ($accountid > 0 && easyback_bank_account_allowed($db, $accountid, $invoicecurrency)) {
			return $accountid;
		}
	}
	return 0;
}

/**
 * Register one bank reversal (payment + bank movement) on a credit note.
 *
 * @param DoliDB $db              Database handler
 * @param User   $user            User
 * @param bool   $isSupplier      True for supplier credit note
 * @param int    $creditnote_id   Credit note id
 * @param string $paytype         Payment mode code
 * @param int    $accountid       Bank account id
 * @param float  $amountinv       Amount in the credit note currency (positive)
 * @param float  $rate            Credit note rate
 * @param string $invoicecurrency Credit note currency
 * @param string $errmsg          Output error message
 * @return int                    >0 on success, <0 on error
 */
function easyback_register_reversal($db, $user, $isSupplier, $creditnote_id, $paytype, $accountid, $amountinv, $rate, $invoicecurrency, &$errmsg)
{
	global $conf;

	// Amount to refund is expressed in the credit note (invoice) currency.
	// The core Paiement::create() converts using the invoice rate stored on the
	// credit note, so we fill:
	//   - ->amounts               = amount in MAIN currency (llx_paiement.amount)
	//   - ->multicurrency_amounts = amount in the credit note currency
	// The bank account must be in the credit note currency (core requirement).
	// addPaymentToBank() then writes llx_bank.amount (bank/invoice currency) and
	// llx_bank.amount_main_currency (main currency).
	if ($invoicecurrency == $conf->currency) {
		$amount_main = abs($amountinv);
	} else {
		$amount_main = price2num(abs($amountinv) / $rate, 'MT');   // main currency
	}
	$amount_inv = abs($amountinv);

	$sign = -1;   // negative on a credit note (reversal)

	// The core only accepts the mode LIQ on a cash account (Account::addline). A cash mode of
	// another currency keeps its own code on the payment and its bank line is recorded as cash.
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$bankcode = $paytype;
	$eacc = new Account($db);
	if ($accountid > 0 && $eacc->fetch($accountid) > 0 && (int) $eacc->courant == Account::TYPE_CASH) {
		$bankcode = 'LIQ';
	}

	if ($isSupplier) {
		$epay = new PaiementFourn($db);
		$epay->datepaye = dol_now();
		$epay->amounts = array($creditnote_id => $sign * $amount_main);
		$epay->multicurrency_amounts = array($creditnote_id => $sign * $amount_inv);
		$epay->multicurrency_code = array($creditnote_id => $invoicecurrency);
		$epay->multicurrency_tx = array($creditnote_id => $rate);
		$epay->paiementcode = $paytype;
		$epay->paiementid = dol_getIdFromCode($db, $paytype, 'c_paiement', 'code', 'id', 1);
		$epay->num_payment = '';
		$epay->fk_account = $accountid;

		// closepaidinvoices = 1 so the core classifies the credit note as paid
		// (setPaid) once the reversal covers its full amount.
		$epayment_id = $epay->create($user, 1);
		if ($epayment_id < 0) {
			$errmsg = $epay->error;
			return -1;
		}

		$epay->paiementcode = $bankcode;
		$eresult = (isModEnabled('bank') ? $epay->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $accountid, '', '') : 1);
		$epay->paiementcode = $paytype;
		if ($eresult < 0) {
			$errmsg = $epay->error;
			return -1;
		}
	} else {
		$epay = new Paiement($db);
		$epay->datepaye = dol_now();
		$epay->amounts = array($creditnote_id => $sign * $amount_main);
		$epay->multicurrency_amounts = array($creditnote_id => $sign * $amount_inv);
		$epay->multicurrency_code = array($creditnote_id => $invoicecurrency);
		$epay->multicurrency_tx = array($creditnote_id => $rate);
		$epay->paiementcode = $paytype;
		$epay->paiementid = dol_getIdFromCode($db, $paytype, 'c_paiement', 'code', 'id', 1);
		$epay->num_payment = '';
		$epay->fk_account = $accountid;

		$epayment_id = $epay->create($user, 1);
		if ($epayment_id < 0) {
			$errmsg = $epay->error;
			return -1;
		}

		$epay->paiementcode = $bankcode;
		$eresult = (isModEnabled('bank') ? $epay->addPaymentToBank($user, 'payment', '(CustomerInvoicePaymentBack)', $accountid, '', '') : 1);
		$epay->paiementcode = $paytype;
		if ($eresult < 0) {
			$errmsg = $epay->error;
			return -1;
		}
	}

	return 1;
}

if (count($epayments) == 0) {
	easyback_ajax_fail($langs->transnoentities('EasyBackErrorSinPagos'));
}
if ($eremaining <= 0) {
	easyback_ajax_fail($langs->transnoentities('EasyBackErrorNadaReembolsar'));
}

// Bank account where each payment was originally registered (fk_bank_line), so
// the reversal goes to the same bank and respects its currency.
foreach ($epayments as $ekey => $epayment) {
	$epayments[$ekey]['account'] = 0;
	if (!empty($epayment['fk_bank_line'])) {
		$ebankline = new AccountLine($db);
		if ($ebankline->fetch((int) $epayment['fk_bank_line']) > 0) {
			$epayments[$ekey]['account'] = (int) $ebankline->fk_account;
		}
	}
}

$error = 0;
$errmsg = '';

if (!empty($epaymentsjson)) {
	// ---- PARTIAL: prorate the money left to refund across the marked channels ----
	$epayments_arr = json_decode($epaymentsjson, true);
	if (!is_array($epayments_arr) || count($epayments_arr) == 0) {
		easyback_ajax_fail($langs->transnoentities('EasyBackErrorSinPagos'));
	}

	// The browser only says which channels (mode + bank account) were marked. Each
	// channel weighs what the invoice really collected through it.
	$echanels = array();
	$epeso_total = 0;
	foreach ($epayments_arr as $ep) {
		$ekeychannel = (isset($ep['type']) ? (string) $ep['type'] : '').'|'.(isset($ep['account']) ? (int) $ep['account'] : 0);
		if (isset($echanels[$ekeychannel])) {
			continue;
		}
		foreach ($epayments as $epayment) {
			if ($epayment['type'].'|'.$epayment['account'] !== $ekeychannel) {
				continue;
			}
			if (!isset($echanels[$ekeychannel])) {
				$echanels[$ekeychannel] = array('type' => $epayment['type'], 'peso' => 0, 'account' => $epayment['account']);
			}
			$echanels[$ekeychannel]['peso'] += abs((float) $epayment['amount']);
			$epeso_total += abs((float) $epayment['amount']);
		}
	}
	if ($epeso_total <= 0) {
		easyback_ajax_fail($langs->transnoentities('EasyBackErrorSinPagos'));
	}

	// The last channel takes the rounding difference, so the channels add up to
	// the exact amount and the credit note can be closed as paid.
	$eassigned = 0;
	$elast = count($echanels) - 1;
	$ei = 0;
	foreach ($echanels as $echannel) {
		if ($ei == $elast) {
			$emonto_canal = price2num($eremaining - $eassigned, 'MT');
		} else {
			$emonto_canal = price2num($eremaining * $echannel['peso'] / $epeso_total, 'MT');
		}
		$ei++;
		if ($emonto_canal <= 0) {
			continue;
		}
		$eassigned += $emonto_canal;

		$eaccountid = easyback_resolve_bank_account($db, $echannel['account'], $einvoicecurrency, $ecn->fk_account);
		if (empty($eaccountid) && isModEnabled('bank')) {
			$error++;
			$errmsg = $langs->transnoentities('EasyBackErrorSinCuenta');
			break;
		}

		$eres = easyback_register_reversal($db, $user, $isSupplier, $creditnote_id, $echannel['type'], $eaccountid, $emonto_canal, $erate, $einvoicecurrency, $errmsg);
		if ($eres < 0) {
			$error++;
			break;
		}
	}
} else {
	// ---- TOTAL: reverse every payment of the source invoice in full ----------
	foreach ($epayments as $epayment) {
		if ($eremaining <= 0) {
			break; // Nothing left to refund on this credit note.
		}

		$eamount_inv = abs((float) $epayment['amount']);
		if ($eamount_inv <= 0) {
			continue;
		}
		// Never refund more than what is left on the credit note.
		if ($eamount_inv > $eremaining) {
			$eamount_inv = $eremaining;
		}
		$epaytype = isset($epayment['type']) ? $epayment['type'] : 'VIR';

		$eaccountid = easyback_resolve_bank_account($db, $epayment['account'], $einvoicecurrency, $ecn->fk_account);
		if (empty($eaccountid) && isModEnabled('bank')) {
			$error++;
			$errmsg = $langs->transnoentities('EasyBackErrorSinCuenta');
			break;
		}

		$eres = easyback_register_reversal($db, $user, $isSupplier, $creditnote_id, $epaytype, $eaccountid, $eamount_inv, $erate, $einvoicecurrency, $errmsg);
		if ($eres < 0) {
			$error++;
			break;
		}

		$eremaining = price2num($eremaining - $eamount_inv, 'MT');
	}
}

if ($error) {
	$db->rollback();
	print json_encode(array('error' => $errmsg ? $errmsg : $langs->transnoentities('Error')));
} else {
	$db->commit();
	print json_encode(array('success' => true));
}

$db->close();

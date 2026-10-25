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
 * \file    class/actions_easyback.class.php
 * \ingroup easyback
 * \brief   Hooks for customer and supplier invoices: replace the native
 *          "Create credit note" flow by the EasyBack return buttons. On the
 *          invoice card the operator can do a PARTIAL return (select lines and
 *          quantities and refund the equivalent money) or a TOTAL return (the
 *          whole invoice). On the POS only the TOTAL return is offered.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Class ActionsEasyback
 */
class ActionsEasyback extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results.
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook (50 if not defined)
	 */
	public $priority = 50;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * addHtmlHeader. Inject JS/CSS on the customer and supplier invoice cards.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$this->resprints = '';

		// Only on the invoice cards (customer and supplier).
		$script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
		if (strpos($script, '/compta/facture/card.php') === false && strpos($script, '/fourn/facture/card.php') === false) {
			return 0;
		}

		$langs->load('easyback@easyback');

		$returnurl = dol_buildpath('/easyback/return.php', 1);
		$token = currentToken();
		$isSupplier = (strpos($script, '/fourn/facture/card.php') !== false);

		$enableVenta = getDolGlobalInt('EASYBACK_DEVOLUCION_VENTA');
		$enableCompra = getDolGlobalInt('EASYBACK_DEVOLUCION_COMPRA');
		if (($isSupplier && !$enableCompra) || (!$isSupplier && !$enableVenta)) {
			return 0;
		}

		$procesar = $langs->trans('EasyBackProcesarDevolucion');
		$seleccion = $langs->trans('EasyBackSeleccionLineas');
		$cancelar = $langs->trans('EasyBackCancelar');

		$this->resprints = '<style nonce="'.getNonce().'">
/* The native "Create credit note" button is replaced by the EasyBack buttons. */
a[href*="fac_avoir="][href*="type=2"] { display: none !important; }
.easyback-col { width: 90px; text-align: center; }
.easyback-col input[type="number"] { width: 60px; text-align: right; }
.easyback-toolbar { margin: 6px 0; }
</style>
<div id="idfordialogeasyback" class="hidden"></div>
<script nonce="'.getNonce().'">
var easybackReturnUrl = "'.dol_escape_js($returnurl).'";
var easybackToken = "'.dol_escape_js($token).'";
var easybackElement = "'.($isSupplier ? 'facture_fourn' : 'facture').'";
var easybackProcesar = "'.dol_escape_js($procesar).'";
var easybackSeleccion = "'.dol_escape_js($seleccion).'";
var easybackCancelar = "'.dol_escape_js($cancelar).'";
var easybackActive = false;
var easybackFacid = 0;
var easybackLblVerNota = "'.dol_escape_js($langs->trans('EasyBackVerNota')).'";
var easybackLblCerrar = "'.dol_escape_js($langs->trans('EasyBackCerrar')).'";
var easybackLblAceptar = "'.dol_escape_js($langs->trans('EasyBackAceptar')).'";

// Native-looking confirmation/notice dialog on the PARENT page (which already
// loads jQuery UI from the core). Called from inside the iframe as
// parent.easybackNotification(...). With options.url it shows "Ver nota" (goto)
// and "Cerrar" (reload original); otherwise a single "Aceptar" button.
function easybackNotification(title, message, options) {
    options = options || {};
    jQuery("#easyback-notify").remove();
    // The message may contain data coming from the server (errors): shown as text.
    var box = jQuery("<div id=\"easyback-notify\" class=\"confirmmessage\" style=\"display:none;\"></div>").text(message);
    jQuery("body").append(box);
    var buttons = {};
    if (options.url) {
        buttons[options.yesLabel || easybackLblVerNota] = function() {
            jQuery(this).dialog("close");
            window.location.href = options.url;
        };
        buttons[options.noLabel || easybackLblCerrar] = function() {
            jQuery(this).dialog("close");
            window.location.reload();
        };
    } else {
        buttons[options.okLabel || easybackLblAceptar] = function() {
            jQuery(this).dialog("close");
            if (options.reload) window.location.reload();
        };
    }
    box.dialog({
        modal: true,
        resizable: false,
        width: 500,
        title: title,
        buttons: buttons
    });
    box.on("dialogclose", function() { jQuery(this).remove(); });
}

// beforeClose of the return dialog (X button, ESC): ask the popup, which shows
// its warning and keeps the dialog open while the refund is pending.
function easybackBeforeClose() {
    var f = jQuery("#idfordialogeasyback iframe")[0];
    try {
        if (f && f.contentWindow && typeof f.contentWindow.easybackCanClose === "function") {
            return f.contentWindow.easybackCanClose();
        }
    } catch (e) {}
    return true;
}

// Parse a formatted amount into a number. Supports both "1,234.56" (en) and
// "1.234,56" (es) by treating the last separator as the decimal separator.
function easybackParseAmount(s) {
    s = String(s || "").replace(/[^0-9.,-]/g, "");
    if (!s) { return 0; }
    var lastComma = s.lastIndexOf(",");
    var lastDot = s.lastIndexOf(".");
    if (lastComma > -1 && lastDot > -1) {
        // Whichever comes last is the decimal separator.
        if (lastComma > lastDot) {
            s = s.replace(/\./g, "").replace(",", ".");
        } else {
            s = s.replace(/,/g, "");
        }
    } else if (lastComma > -1) {
        if ((s.match(/,/g) || []).length > 1) { s = s.replace(/,/g, ""); }
        else { s = s.replace(",", "."); }
    } else if (lastDot > -1) {
        if ((s.match(/\./g) || []).length > 1) { s = s.replace(/\./g, ""); }
    }
    return parseFloat(s) || 0;
}

// PARTIAL return: toggle the line/quantity selection directly on the invoice
// lines. When active, each line gets a checkbox + a quantity input and a toolbar
// with "Procesar devolución". Only the marked lines/quantities are returned.
function EasyBackToggleMode(facid) {
    var table = jQuery("#tablelines");
    if (!table.length) { return; }
    if (!easybackActive) {
        easybackActive = true;
        easybackFacid = facid || 0;
        // Header column
        var thead = table.find("thead > tr");
        if (thead.length && !thead.find(".easyback-col").length) {
            thead.prepend("<th class=\\"easyback-col\\">Sel.</th>");
        }
        // Add cells + remember the unit price (HT total / original qty).
        table.find("tbody tr[data-id]").each(function() {
            var row = jQuery(this);
            var lineid = row.attr("data-id");
            var qty = row.attr("data-qty") || "1";
            if (row.find(".easyback-col").length) { return; }
            var htTotalCell = row.find("td.linecolht");
            var htTotalVal = htTotalCell.find(".classfortooltip").first().text() || htTotalCell.first().text() || "";
            var htTotal = easybackParseAmount(String(htTotalVal));
            var unitPrice = qty ? (htTotal / parseFloat(qty)) : 0;
            row.data("easyback-unitprice", unitPrice);
            var cell = jQuery("<td class=\\"easyback-col\\"></td>");
            cell.append("" +
                "<input type=\\"checkbox\\" class=\\"easyback-line-check\\" name=\\"easyback_lineid[]\\" value=\\"" + lineid + "\\">" +
                "<input type=\\"number\\" class=\\"easyback-qty\\" name=\\"easyback_qty[" + lineid + "]\\" value=\\"" + qty + "\\" min=\\"1\\" step=\\"any\\">"
            );
            row.prepend(cell);
        });
        // Recompute the line HT total cell when the quantity changes.
        table.find("tbody tr[data-id]").on("change", "input.easyback-qty", function() {
            var row = jQuery(this).closest("tr");
            var qty = parseFloat(jQuery(this).val()) || 0;
            var unitPrice = parseFloat(row.data("easyback-unitprice")) || 0;
            var newTotal = unitPrice * qty;
            var htCell = row.find("td.linecolht");
            var tooltip = htCell.find(".classfortooltip").first();
            if (tooltip.length) { tooltip.text(newTotal ? newTotal.toFixed(2) : "0.00"); }
            else { htCell.first().text(newTotal ? newTotal.toFixed(2) : "0.00"); }
        });
        EasyBackShowToolbar(true);
    } else {
        EasyBackToggleModeOff();
    }
}

function EasyBackToggleModeOff() {
    easybackActive = false;
    jQuery("#tablelines thead .easyback-col").remove();
    jQuery("#tablelines .easyback-col").remove();
    EasyBackShowToolbar(false);
}

function EasyBackShowToolbar(show) {
    if (show) {
        if (jQuery("#easyback-toolbar").length) { return; }
        var bar = jQuery("<div class=\\"easyback-toolbar\\" id=\\"easyback-toolbar\\"></div>");
        bar.append("<span class=\\"opacitymedium\\">&nbsp;" + easybackSeleccion + "</span> ");
        bar.append("<button type=\\"button\\" class=\\"butAction\\" onclick=\\"EasyBackSubmit()\\">&nbsp;" + easybackProcesar + "</button> ");
        bar.append("<a class=\\"butActionRefused\\" href=\\"#\\" onclick=\\"EasyBackToggleModeOff(); return false;\\">&nbsp;" + easybackCancelar + "</a>");
        jQuery("#tablelines").before(bar);
    } else {
        jQuery("#easyback-toolbar").remove();
    }
}

function EasyBackSubmit() {
    var any = false;
    var lines = [];
    jQuery("#tablelines").find("tr[data-id]").each(function() {
        var row = jQuery(this);
        var cb = row.find("input.easyback-line-check");
        if (cb.is(":checked")) {
            any = true;
            lines.push({ lineid: cb.val(), qty: row.find("input.easyback-qty").val() || "1" });
        }
    });
    if (!any) {
        easybackNotification(easybackSeleccion, "'.dol_escape_js($langs->trans('EasyBackErrorSinLineas')).'");
        return;
    }
    var url = easybackReturnUrl + "?token=" + encodeURIComponent(easybackToken) +
        "&facid=" + encodeURIComponent(easybackFacid) +
        "&mode=partial" +
        "&element=" + encodeURIComponent(easybackElement);
    for (var i = 0; i < lines.length; i++) {
        url += "&lineid[]=" + encodeURIComponent(lines[i].lineid) +
               "&qty[" + encodeURIComponent(lines[i].lineid) + "]=" + encodeURIComponent(lines[i].qty);
    }
    var pb = jQuery("#idfordialogeasyback");
    pb.html("<iframe class=\\"iframedialog\\" style=\\"border:0px;\\" src=\\"" + url + "\\" width=\\"100%\\" height=\\"98%\\"></iframe>");
    pb.dialog({
        autoOpen: true,
        modal: true,
        height: (window.innerHeight - 150),
        width: "85%",
        title: easybackProcesar,
        beforeClose: easybackBeforeClose,
        close: function() { pb.html(""); }
    });
}

// Fallback for the CSS rule above (covers elements added after DOM ready):
// hide the native "Create credit note" button on customer and supplier cards.
jQuery(function() {
    jQuery("a[href*=\\"fac_avoir=\\"][href*=\\"type=2\\"]").hide();
});

// Open the TOTAL return popup. No line/qty selection: the whole invoice is
// returned (all lines, full quantities), the stock is returned and the money
// collected is refunded.
function EasyBackOpenTotalReturn(facid) {
    var url = easybackReturnUrl + "?token=" + encodeURIComponent(easybackToken) +
        "&facid=" + encodeURIComponent(facid) +
        "&mode=total" +
        "&element=" + encodeURIComponent(easybackElement);
    var dialogbox = $("#idfordialogeasyback");
    dialogbox.html("<iframe class=\\"iframedialog\\" style=\\"border:0px;\\" src=\\"" + url + "\\" width=\\"100%\\" height=\\"98%\\"></iframe>");
    dialogbox.dialog({
        autoOpen: true,
        modal: true,
        height: (window.innerHeight - 150),
        width: "85%",
        title: easybackProcesar,
        beforeClose: easybackBeforeClose,
        close: function() { dialogbox.html(""); }
    });
}
</script>';

		return 0;
	}

	/**
	 * addMoreActionsButtons. Add the "Devolución" (partial) and "Devolución total"
	 * buttons to the invoice action bar.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		$langs->load('easyback@easyback');

		if (!is_object($object)) {
			return 0;
		}

		$isSupplier = ($object->element == 'invoice_supplier');
		$isCustomer = ($object->element == 'facture');

		if (!$isSupplier && !$isCustomer) {
			return 0;
		}

		if ($isSupplier && !getDolGlobalInt('EASYBACK_DEVOLUCION_COMPRA')) {
			return 0;
		}
		if ($isCustomer && !getDolGlobalInt('EASYBACK_DEVOLUCION_VENTA')) {
			return 0;
		}

		// Only for invoices that can be credited: not a credit note, status validated/closed, has status > 0.
		if ($object->type == 2) { // Already a credit note (TYPE_CREDIT_NOTE)
			return 0;
		}
		if ((int) $object->status <= 0) {
			return 0;
		}

		// Replicate the native permission logic (card.php) so the button shows
		// under the same conditions the core would allow creating a credit note.
		$usercanvalidate = false;
		if ($isSupplier) {
			$usercancreate = ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
			$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
				|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate')));
		} else {
			$usercancreate = $user->hasRight('facture', 'creer');
			$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
				|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'validate')));
		}

		if (!$usercanvalidate) {
			return 0;
		}

		// Partial return: select the lines/quantities on the card.
		print '<a class="butAction reposition" href="#" onclick="EasyBackToggleMode('.(int) $object->id.'); return false;">'.$langs->trans('EasyBackDevolucion').'</a>';
		// Total return: the whole invoice (all lines, full quantities).
		print '<a class="butAction reposition" href="#" onclick="EasyBackOpenTotalReturn('.(int) $object->id.'); return false;">'.$langs->trans('EasyBackDevolucionTotal').'</a>';

		return 0;
	}

	/**
	 * completeTakePosInvoiceHeader. Replace the TakePOS "Create credit note" button
	 * by the EasyBack "Total return" button on the POS invoice screen.
	 *
	 * @param array<string,mixed> $parameters  Hook metadata
	 * @param CommonObject        $object      The invoice
	 * @param string              $action      Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';

		// Only on the TakePOS invoice fragment (loaded by $("#poslines").load(...)).
		$script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
		if (strpos($script, '/takepos/invoice.php') === false) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		if (!is_object($object) || empty($object->element) || $object->element != 'facture' || empty($object->id)) {
			return 0;
		}
		// Never on a credit note itself.
		if ((int) $object->type == Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		// Same feature flag as the customer invoice card.
		if (!getDolGlobalInt('EASYBACK_DEVOLUCION_VENTA')) {
			return 0;
		}
		// Note: TAKEPOS_NO_CREDITNOTE only hides the native core button. EasyBack provides its
		// own return button, so it must still be shown when that constant hides the native one.
		$estatus = (int) $object->status;
		if ($action != 'valid' && $action != 'history' && !($action == 'addline' && $estatus == Facture::STATUS_CLOSED)) {
			return 0;
		}
		// Replicate the native permission logic (card.php): create + validate.
		$usercancreate = $user->hasRight('facture', 'creer');
		$usercanvalidate = ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $usercancreate)
			|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'validate')));
		if (!$usercancreate || !$usercanvalidate) {
			return 0;
		}

		$langs->load('easyback@easyback');

		$ereturnurl = dol_buildpath('/easyback/return.php', 1);
		// The terminal and its stock setup (no stock change, warehouse) are read by
		// return.php from the session and the TakePOS setup, never from the URL.
		$equery = '&popup=colorbox&mode=total';

		$elabel = $langs->trans('EasyBackDevolucionTotal');
		$etitle = $langs->trans('EasyBackDevolucionTotal');
		$eaceptar = $langs->trans('EasyBackAceptar');

		$this->resprints = '<script>
if (typeof EasyBackPosReturn === "undefined") {
	window.EasyBackPosReturn = function(invoiceid) {
		$.colorbox({href: "'.dol_escape_js($ereturnurl).'?token=" + encodeURIComponent("'.dol_escape_js(currentToken()).'") + "&element=facture'.dol_escape_js($equery).'&facid=" + encodeURIComponent(invoiceid), width: "80%", height: "90%", transition: "none", iframe: "true", title: "'.dol_escape_js($etitle).'"});
	};
}
// The colorbox X, ESC and overlay click all go through $.colorbox.close(): while
// the refund of the return popup is pending, it shows its warning instead of closing.
if (jQuery.colorbox && !jQuery.colorbox.easybackWrapped) {
	var easybackColorboxClose = jQuery.colorbox.close;
	jQuery.colorbox.close = function() {
		var f = jQuery("#cboxLoadedContent iframe")[0];
		try {
			if (f && f.contentWindow && typeof f.contentWindow.easybackCanClose === "function" && !f.contentWindow.easybackCanClose()) {
				return;
			}
		} catch (e) {}
		return easybackColorboxClose.apply(this, arguments);
	};
	jQuery.colorbox.easybackWrapped = true;
}
if (typeof EasyBackPosNotify === "undefined") {
	window.EasyBackPosNotify = function(title, message) {
		if (jQuery.colorbox) { jQuery.colorbox.close(); }
		jQuery("#easyback-pos-notify").remove();
		var box = jQuery("<div id=\"easyback-pos-notify\" class=\"confirmmessage\" style=\"display:none;\"></div>").text(message);
		jQuery("body").append(box);
		box.dialog({
			modal: true,
			resizable: false,
			width: 500,
			title: title,
			buttons: { "'.dol_escape_js($eaceptar).'": function() {
				jQuery(this).dialog("close");
				if (typeof Refresh === "function") { Refresh(); }
			} }
		});
		box.on("dialogclose", function() { jQuery(this).remove(); });
	};
}
jQuery(function() {
	var escope = jQuery("#poslines");
	if (!escope.length) { escope = jQuery(document); }
	var enative = escope.find("button[onclick*=\\"ModalCreditNote\\"]");
	enative.hide();
	if (jQuery("#easyback-pos-return").length) { return; }
	var ebtn = jQuery("<button type=\\"button\\" id=\\"easyback-pos-return\\" class=\\"button\\" onclick=\\"EasyBackPosReturn('.(int) $object->id.');\\">'.dol_escape_js($elabel).'</button>");
	if (enative.length) {
		enative.first().after(ebtn);
	} else {
		var eref = escope.find("#tablelines a[href*=\\"facture/card.php?id=\\"]").first();
		if (eref.length) { eref.after(ebtn); }
		else if (jQuery("#tablelines").length) { jQuery("#tablelines").before(ebtn); }
	}
});
</script>';

		return 0;
	}
}

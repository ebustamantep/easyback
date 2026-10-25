<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Edgar Bustamante				<ebustamantep@gmail.com>
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
 * 	\defgroup   easyback     Module EasyBack
 *  \brief      EasyBack module descriptor.
 *
 *  \file       htdocs/easyback/core/modules/modEasyBack.class.php
 *  \ingroup    easyback
 *  \brief      Description and activation file for module EasyBack
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module EasyBack
 */
class modEasyBack extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		$this->numero = 958600;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'easyback';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed)
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description
		$this->description = "EasyBackDescription";
		$this->descriptionlong = "EasyBackDescription";

		// Author
		$this->editor_name = 'Edgar Bustamante';
		$this->editor_url = '';
		$this->editor_squarred_logo = '';

		$this->version = '1.0.6';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'fa-undo';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			// Hooks context managed by module.
			'hooks' => array(
				'data' => array(
					'invoicecard',
					'invoicesuppliercard',
					'takeposinvoice',
					'main',
				),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/easyback/temp");

		// Config pages. Put here list of php page, stored into easyback/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@easyback");

		// Dependencies
		$this->hidden = getDolGlobalInt('MODULE_EASYBACK_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("easyback@easyback");

		// Prerequisites
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants / feature flags. visible = 1 -> listed into admin/const.php (llx_const)
		$this->const = array(
			1 => array('EASYBACK_DEVOLUCION_VENTA', 'chaine', '1', 'Mostrar botón Devolución en facturas de venta', 1, 'current', 0),
			2 => array('EASYBACK_DEVOLUCION_COMPRA', 'chaine', '1', 'Mostrar botón Devolución en facturas de compra', 1, 'current', 0),
			3 => array('EASYBACK_AUTO_VALIDAR', 'chaine', '1', 'Validar automáticamente la factura de abono al procesar la devolución', 1, 'current', 0),
			4 => array('EASYBACK_REINTEGRO_STOCK', 'chaine', '1', 'Delegar el reintegro/salida de stock al validate() del core', 1, 'current', 0),
		);

		if (!isModEnabled("easyback")) {
			$conf->easyback = new stdClass();
			$conf->easyback->enabled = 0;
		}

		// No tabs and no top menu: this module only hooks into existing invoice cards.
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions provided by this module (none, uses core invoice rights)
		$this->rights = array();

		// No menu entry (taste: no clutter in top menu)
		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		$result = $this->_load_tables('/easyback/sql/');
		if ($result < 0) {
			return -1;
		}

		// Permissions
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}

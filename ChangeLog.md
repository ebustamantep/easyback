# CHANGELOG MODULE EASYBACK FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.6

- **La terminal del POS sale de la sesión**, no de la URL: antes se podía atribuir una devolución al cierre de caja de otra terminal (`posterminal`) o saltarse el reintegro de stock (`noreturnstock`). El almacén se valida (existe, abierto, entidad del usuario).
- **Validación de la factura de origen**: acceso al registro (entidad y restricción por tercero), validada o pagada y que no sea un abono. Antes se podía devolver un borrador, una factura abandonada o de otra entidad llamando a la URL.
- **Sin dobles devoluciones ni dobles reembolsos**: la factura de origen y el abono se bloquean (`FOR UPDATE`) durante la creación/validación del abono y durante el reembolso. Si la validación falla, se deshace todo (abono y stock).
- **Cuenta del reverso**: solo la cuenta original o la del abono, abierta y en moneda principal o del abono. Ya no se reembolsa desde "la primera cuenta abierta".
- No se copia `fk_remise_except` a las líneas del abono (el descuento queda ligado solo a la factura original).
- Sin el módulo Bancos, el reverso se registra sin movimiento bancario.
- XSS: los mensajes de error y avisos se muestran como texto.
- Los modos de pago creados por el usuario sin traducción (`EFBS`...) muestran la etiqueta del diccionario.

## 1.0.5

- **Requiere Dolibarr 23.** El módulo usa `getListOfPayments()` con `fk_bank_line`, `dolBuildUrl()`, la firma actual de `addline()` y el hook `completeTakePosInvoiceHeader`; antes declaraba compatibilidad con la 19 y se podía activar en versiones donde falla.
- **Cada devolución parcial genera su propia factura de abono.** Antes, una segunda devolución parcial de la misma factura reutilizaba el abono ya validado: no reintegraba el stock y el reembolso sobrepagaba ese abono.
- **Se descuenta lo ya devuelto.** La devolución total y la parcial sólo ofrecen lo que queda por devolver de cada línea (lo facturado menos lo devuelto por los abonos validados). Una devolución total después de una parcial devuelve el resto en vez de quedar bloqueada como "ya devuelta". Los abonos abandonados no cuentan.
- **El reembolso lo calcula el servidor.** `return_ajax.php` ya no recibe el importe ni el peso de cada canal desde el navegador: reembolsa lo pendiente del abono, nunca más de lo cobrado en la factura menos lo ya reembolsado por sus abonos, y reparte por lo que realmente se cobró en cada canal marcado. Un doble envío ya no duplica el reembolso.
- El último canal del prorrateo recibe la diferencia de redondeo, así el abono queda pagado por completo.
- **CSRF obligatorio** en `return.php` y `return_ajax.php`: antes, una petición sin token se aceptaba y creaba y validaba un abono.
- `return.php` ya no acepta `creditnote_id`: sólo reemplaza el borrador de abono de la propia factura (antes un id arbitrario podía borrar otro borrador).
- **Aviso al cerrar el popup sin reembolsar.** Cuando el abono ya está validado (stock reintegrado) pero el dinero aún no se reembolsó, cerrar el popup muestra un aviso con el abono y el importe pendiente, con "Volver al reembolso" o "Cerrar sin reembolsar". Cubre el botón "Cerrar", la X y ESC del diálogo de la ficha (`beforeClose`), la X, ESC y clic fuera del colorbox del POS (`$.colorbox.close`), y cerrar o recargar la pestaña (`beforeunload`).
- Fix: con varios almacenes, el formulario para elegir el almacén perdía las líneas de la devolución parcial y el origen POS (terminal, popup, stock).

## 1.0.4

- **Fix: devolución por una caja con un modo distinto de `LIQ`** (p. ej. Efectivo Bs de POSPlus). El core solo admite `LIQ` en cuentas de tipo Caja (`ErrorCashAccountAcceptsOnlyCashMoney`); el movimiento bancario del reverso se registra como efectivo y el pago conserva su modo.
- **Las constantes del módulo se conservan al desactivarlo**: reactivar el módulo tras actualizar (necesario para regenerar los hooks) ya no devuelve la configuración a sus valores por defecto.

## 1.0.3

- **Fix POS: "Devolución total" no aparecía.** Con `TAKEPOS_NO_CREDITNOTE = 1` (puesta para ocultar el botón nativo "Crear factura de abono" del core), el hook `completeTakePosInvoiceHeader` **se desactivaba a sí mismo** (heredaba ese check), así que en el POS no aparecía ningún botón. Se **desacopla**: esa constante sólo gobierna el botón *nativo* del core; EasyBack pinta su propio botón y sólo oculta el nativo si existe.
  - Además, la inserción del botón ya **no depende de encontrar el botón nativo**: si no existe, se ancla al enlace del nº de factura. La búsqueda se acota a `#poslines` (antes capturaba también los botones del modal `ModalCreditNote`).
- Se separan los dos flujos que se habían fusionado en la 1.0.1:
  - **Card de factura (Financiera: venta y compra) → devolución PARCIAL.** El botón **"Devolución"** entra en modo selección sobre las líneas de la factura (checkbox + cantidad por línea, recalculando el total de la línea en vivo). Sólo se devuelven las líneas/cantidades marcadas y en el popup se marca qué canales de pago reversar: el dinero equivalente (total TTC de lo devuelto) se **prorratea** entre los canales marcados según su peso original.
  - **POS (TakePOS) → devolución TOTAL.** El botón "Devolución total" del POS devuelve todas las líneas a cantidad completa y reversa cada pago por su importe completo. El card mantiene además un botón **"Devolución total"** para el mismo flujo.
- **Fix del hook del POS:** se declara el contexto `takeposinvoice` en `module_parts['hooks']['data']`. Antes no estaba declarado, por lo que Dolibarr no cargaba `ActionsEasyback` en el POS y el hook `completeTakePosInvoiceHeader` **nunca se ejecutaba**. Dolibarr reconstruye los hooks desde la constante `MAIN_MODULE_EASYBACK_HOOKS` de `llx_const`, así que **es necesario deshabilitar y volver a habilitar el módulo** tras actualizar para regenerarla.
- `return.php` acepta un parámetro `mode` (`partial`/`total`); si no viene, se infiere (selección de líneas ⇒ parcial, si no ⇒ total). `return_ajax.php` prorratea el reverso cuando recibe los canales marcados (`payments`) y reversa completo cuando no.
- Se recupera el **total de la línea en vivo** al cambiar la cantidad y el parseo localizado de importes (`easybackParseAmount`, coma/punto decimal).
- Traducciones es_ES/en_US: se restaura el juego de claves de la devolución parcial junto a las del total.

## 1.0.2

- Devolución total también desde el **POS (TakePOS)**: el botón nativo "Crear factura de abono" del POS (`takepos/invoice.php`, modal `ModalCreditNote`) se sustituye por **"Devolución total"**, que ejecuta el mismo flujo de EasyBack: abono con todas las líneas, validación con reintegro de stock y reverso bancario de los pagos originales.
  - Se implementa con el hook `completeTakePosInvoiceHeader` (el mismo que usa POSPlus), sin tocar el core de takepos.
  - El popup se abre en el mecanismo nativo del POS (`$.colorbox` con iframe, igual que POSPlus) y al terminar cierra y recarga el POS (`Refresh()`).
  - El botón sólo aparece en las mismas condiciones que el nativo (acción `valid`/`history`, no abono) y con los mismos permisos (`facture->creer` + validar).
  - Almacén: se usa el del terminal (`CASHDESK_ID_WAREHOUSE<term>`) sin volver a preguntar; si el terminal tiene `CASHDESK_NO_DECREASE_STOCK<term> = 1` (no mueve stock en la venta) la devolución tampoco mueve stock, replicando el comportamiento de takepos.
  - El abono creado desde el POS se marca con su **origen** (`module_source = 'easyback'`, `pos_source = <terminal>`), para que el cierre de caja de POSPlus contabilice la devolución en la terminal correcta y **solo** las devoluciones hechas desde el punto. No se usa `takepos` como `module_source` a propósito: así el abono conserva su numeración (`IC...`) y no entra en el numerador de ventas del POS.
- `return.php` acepta el modo popup (`popup=colorbox`) y el almacén/flags del POS; cierre y recarga del padre unificados (`easybackCloseParent()` / `easybackReloadParent()`).

## 1.0.1

- El botón **Devolución** pasa a ser una **devolución total**: reemplaza al botón nativo "Crear factura de abono" y ejecuta todo el proceso en un solo flujo.
  - La factura de abono se crea con **todas** las líneas de la factura y **sus cantidades completas** (se elimina la selección parcial de líneas/cantidades del módulo).
  - Se valida el abono delegando el reintegro/salida de stock en el `validate()` del core (`EASYBACK_REINTEGRO_STOCK`).
  - Se devuelve el **dinero realmente cobrado**: cada pago de la factura se reversa **por su importe completo**, en su **mismo canal de pago y misma cuenta bancaria** donde se registró originalmente (ya no se prorratea un monto parcial).
  - Si la factura no tiene pagos, el abono solo cancela la deuda y no se registra movimiento bancario.
- El popup muestra ahora el resumen de líneas devueltas y los pagos a reversar, con un único botón "Procesar devolución total".
- Guarda contra doble devolución: si la factura ya tiene un abono validado, el popup lo avisa con enlace al abono y no permite repetir.
- El listado de pagos usa `getListOfPayments(..., 1)` (mode=1): solo pagos reales, sin colar abonos/descuentos/anticipos como si fueran pagos.
- Con varios almacenes el almacén es obligatorio para validar (evita un abono sin movimiento de stock).

## 1.0.0

- Versión inicial del módulo (devoluciones unificadas en ventas y compras).
- Fix reembolso bancario parcial: el monto a devolver = total de lo realmente devuelto (abono), repartido proporcionalmente entre los canales de pago marcados. Antes se reversaba el monto completo del pago original.
- Abono validado reutilizado de un intento previo: se recalcula el monto a devolver a partir de las líneas/cantidades marcadas, evitando reversar el monto completo del abono.
- Fix multimoneda en el reverso bancario: el monto a devolver se calcula en la moneda de la factura/abono y se convierte a la moneda principal con la tasa de la factura. El banco destino se elige en la moneda del abono para respetar la moneda de la cuenta y registrar `llx_bank.amount` (moneda banco) con su equivalente `amount_main_currency` (moneda principal).
- Fix: al procesar el reembolso la factura de abono quedaba como pendiente de pago. Ahora `Paiement::create()`/`PaiementFourn::create()` se llaman con `closepaidinvoices=1` para que el core clasifique el abono como pagado (`setPaid` => `paye=1`, `fk_statut=STATUS_CLOSED`) cuando el reembolso cubre su importe.
- Fix: el reembolso iba a la primera cuenta de la lista en vez de la cuenta correcta. Ahora cada canal de pago lleva la cuenta bancaria donde se registró originalmente (`data-account`), y el reembolso se registra en esa misma cuenta (respetando su moneda). Si el canal no tiene cuenta, se usa una cuenta en la moneda del abono como fallback.

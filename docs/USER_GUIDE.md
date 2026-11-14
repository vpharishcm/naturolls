# User guide

## Taking an order

1. Tap **New Order**. The order is created and saved at your first tap.
2. Tap **+** on each item. Each item shows how many are left.
3. Tap **+ Name** to add an optional customer name or mobile number. A mobile number links the bill to the customer's history and points.
4. Hand the items over. The order stays open until the customer pays. Tap **Back** to leave it open.

Two very quick taps on the same button (under 0.18 seconds) count as one, so an accidental double tap does not add an extra item.

**Closing the keyboard.** Tap **Done** above the keyboard, the keyboard's own Enter key, or anywhere else on the screen.

**Showing items.** Tap **Cards** or **List** beside the search box. Each device remembers its choice.

**Favourites.** Tap the heart on an item. Favourites appear first under **♥ Favourites**, on every device, and tapping the heart again removes it. This needs a connection.

## Changing an open order

**Orders to Be Billed** lists every open order with its items, for example `2 × Mango`, so you can tell apart two orders with the same total. Tap an order to change it. You can:

- **Add items** to go back to the tiles, or use **+** and **−** on a line.
- **Served**: mark an item as handed over. Stock is deducted now instead of at billing. This is optional, because billing deducts anything not yet marked.
- **Remove**: take an item off. If it was already served, stock stays deducted unless you choose to return it, which needs the owner PIN.
- **Merge into another order**: for a second order opened by mistake for the same customer. Pick the customer's first order from the list, which shows each order's items. Everything moves across, same items join one line, and this order closes as **Merged** with a link to the order that took its items. An empty order opened by mistake can be merged away too.
- **Move items**: to split an order, or fix an item added to the wrong one. Choose how many of each item to move, then send them to **A new order** or **Another open order**. At least one item must stay; to move everything, use Merge.
- **Cancel this order**: needs a reason and the owner PIN. Unserved items go back to stock. Served items stay deducted.

Merging and moving need no PIN and do not change stock: reserved items stay reserved and served items stay served, on whichever order they end up. Only unbilled orders can be merged or moved, and orders still saved on the device must sync first. Each merge and move is kept in **Settings › Audit**.

## Billing

1. Tap **BILL IT**.
2. Add a discount or use points if needed. All the automatic offers that apply are added for you; tap an offer to turn it on or off, or choose **No offer**.
3. Choose **Cash**, **UPI**, **Card** or **Split**. A reference number is optional for UPI and card. Enter cash received to see the change.
4. Tap **CONFIRM**, then **PRINT BILL**.

If the order changed on another device, you are shown the new items and asked to confirm again. If printing fails, the bill and payment are still saved; tap **TRY PRINT AGAIN**.

To reprint or refund, open the bill from **All Orders**. Reprints are marked `*** DUPLICATE ***`. Refunds need the owner PIN and do not put stock back.

## Editing a bill

Open the bill from **All Orders** and tap **Edit bill**. Change quantities, remove items or add new ones, and enter a reason. The screen shows the new total and how much to collect or give back. Choose how that money moved and press **SAVE EDITED BILL**, then print the new bill, which is marked `*** EDITED BILL ***`.

- **Time limit:** staff can edit for 30 minutes after billing. The owner can edit at any time and can change the limit in **Settings › Business**.
- **Stock:** removed items go back in stock unless you untick **Put removed items back in stock**. Added items need stock available.
- **Discounts:** the bill's offers, rupees of manual discount and points carry over. An offer is dropped if the bill no longer meets its terms.
- **Not editable:** a bill that has had a refund.
- **Records:** the old items, total and reason are kept in **Settings › Audit**, and changes appear in the staff modifications report.

## Fixing the payment mode

If the wrong payment mode was tapped (for example Cash instead of UPI), tap **Wrong payment mode? Change it** on the Paid! screen, or open the bill from **All Orders** and tap **Change payment mode**. Pick the right mode, or tap **+ Split between modes** if the customer paid part by each. Enter a reason and press **SAVE PAYMENT**. The amount received stays the same; only how it came in changes, and the day's Cash, UPI and Card totals in **Payments** move with it.

- **Time limit:** staff can change it for 30 minutes after billing. The owner can change it at any time and can set the limit in **Settings › Business** (**Staff can change how a bill was paid for**).
- **Not changeable:** a bill with a refund, a payment already matched with the bank or UPI statement, or a bill from a day whose cash is already closed.
- **Records:** the old and new payment modes and the reason are kept in **Settings › Audit**.

## Offers

Create offers in **Settings › Offers**. Choose what each one covers: whole order, selected items or selected categories.

- **Percent** or **Fixed ₹**: money off.
- **Buy & get free**: for example buy 2 get 1 free. The **Buy section** lists the items the customer pays for, and the **Get section** lists the items that are free, or **Same as buy**. So you can offer buy 2 Mango, get 1 Vanilla Scoop free. Add all 3 items to the order, including the free one, so stock stays right. If several items could be free, the cheapest are free. Each full set earns the offer again, so with the same items a bill of 6 gets 2 free.
- **Set price**: for example 4 scoops for ₹300. Extra scoops pay the normal price. It applies only when the set price is cheaper than the normal prices.

Every active offer that helps applies to the bill at once: with "2 Milk for ₹90" and "2 Water for ₹40", a bill with 2 Milk and 2 Water gets both. Each item counts towards one offer only, and when offers compete for the same items the bill uses the mix that saves the customer the most. Staff can tap offers under **Offers** on the bill screen to turn them on or off, choose **No offer**, or tap **Best offers** to go back to the automatic choice. Offers that need a manager's PIN are never applied automatically.

## When the internet is down

A black bar shows **Offline**. Keep taking new orders: they are saved on the device with numbers like `261101-TDK1-0001`, which you can give to the customer.

- Billing needs a connection. Offline orders can be billed once they have synced.
- Changes to an order that is already on the server wait on screen and are sent when the connection returns.
- Offline orders sync by themselves within about 15 seconds of reconnecting.

After syncing, a note may ask you to check an order. An item short of stock must be fixed before billing: reduce or remove it, or add stock and tap **Try stock again**.

## Daily production

Open **Daily Production**, enter a quantity for each flavour and tap **ADD TO STOCK**. To correct a mistake, open **History** and tap **Fix**. This needs a reason and the owner PIN. Stock that has been sold or reserved cannot be reversed.

## For the owner

- **Products**: change prices in ₹5 steps and press **SAVE CHANGES**. Tap an item name for its photo, GST, cost, SKU, variants, minimum stock and stock settings. **Delete** hides the item; past bills keep it.
- **Inventory**: **Stock count** sets the real count, **Wastage** records spoiled items, and **History** shows every movement. Available stock is physical stock minus reserved stock.
- **Payments**: count the drawer and press **CLOSE CASH** at the end of the day. **To reconcile** lists UPI and card payments to match against your statements.
- **Reports**: choose a report and dates, then export to Excel, CSV or PDF, or print.
- **Settings**: business details, printer, offers, loyalty rules, staff, backups and the audit history.

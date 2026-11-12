# Thermal printer setup

Open **Settings › Printer**, pick the method that matches your printer, choose 58 mm or 80 mm paper and press **SAVE CHANGES**. The method applies to all devices. Each Bluetooth or USB device connects to the printer separately.

| Method | Use when | Needs |
| --- | --- | --- |
| **System print** | The device already prints through Android or Windows | Nothing extra. Set the paper size in the print dialog once. |
| **Bluetooth** | A Bluetooth printer sits next to the tablet | Chrome on Android, HTTPS, and a Bluetooth Low Energy printer |
| **USB** | A USB printer is plugged into the device | Chrome, HTTPS, and a printer the browser can claim |
| **Network** | The printer has an IP address | The **server** can reach the printer on port 9100, so not on online or shared hosting |

Bluetooth, USB and Network send ESC/POS commands, which most thermal receipt printers understand.

## Connecting

- **Network**: enter the IP address and port. The page shows whether the printer is reachable, and rechecks every 10 seconds. Give the printer a fixed IP address on your router.
- **Bluetooth or USB**: tap **Connect printer** and choose it from the browser's list. After a page reload the browser may ask you to choose it again, because it needs a tap to reconnect.

Classic (non-BLE) Bluetooth printers do not appear in the browser. For those, use a printer service app on Android with **System print**.

## Receipt layout

The receipt is compact to save paper. Invoice and order numbers share one row, and each item is one row under the headings Item, Price, Qty and Amt. On narrow paper the labels shorten and the order number loses its hyphen. A long item name continues on the next line. **Subtotal** appears only when there is a discount or GST. When a customer pays more than the bill, the receipt shows both amounts on one row: `CASH given 50.0   Change 30.0`.

## What happens if printing fails

The invoice and payment are saved before printing starts, so a printer fault cannot undo a bill. The screen says it did not print and offers **TRY PRINT AGAIN**. Each attempt appears in the **Print log**.

The first successful print is the original. Later prints are marked `*** DUPLICATE ***`. A failed first attempt does not count, so the retry is not marked as a duplicate.

System print cannot tell whether paper came out. It records success once the dialog closes, even if it was cancelled. Use **Reprint** if nothing printed; that copy will be marked as a duplicate.

## Test checklist

Check these with your printer before going live, because this was tested with a simulated network printer and not with physical hardware:

1. Print a bill at your paper width and check the columns line up.
2. Check the paper cuts, or feeds far enough to tear. The feed after the footer was shortened to save paper, so confirm the footer is not cut off.
3. Check the ₹ symbol. ESC/POS receipts print `Rs` because many printers lack the symbol.
4. Turn the printer off, print, and confirm the failure message appears. Turn it on and retry.

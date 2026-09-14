# Customers

This first increment introduces **Customers** as the commerce directory. It must be deployed and migrated before these screens appear in a running installation.

Open Customers to create a record with name, contact email, phone and date of birth. Creating a customer does not create a portal login or grant access to a clinical record. Customer contact email can differ from the email used to sign in.

Open a customer to review details and provider references. Choose Edit to update contact information. Provider identifiers are read-only here. Search the directory using the Customer ID or PRX patient number; name/email search is not available in this increment.

In Addresses, choose Add address, select Shipping or Billing, enter the address and optionally mark it as the default for that type. You can edit an existing address. Shipping and billing have separate defaults. Updating the address book does not alter the addresses recorded on previous orders.

For an associated account, authorized staff can use Portal account and security to open the existing session/verification controls. Portal accounts remain separately accessible under the Customers navigation group. Viewing Customer details does not automatically grant access to those security controls.

Staff need Customer view/create/update permissions for the corresponding actions. There are no customer deletion controls in this increment. New accounts created through the existing verified enrollment or staff Portal accounts screen now receive a Customer record. An operator can preview and apply a local backfill for existing active accounts; provider customers are not imported. The Orders relation shows active local orders assigned to this Customer when staff also have Order list and view permissions. Open View order for a read-only summary, items, shipments and checkout-attempt state. Saved payment methods, transactions, refund/void actions and subscription management remain future work; these screens do not process payments.


Provider mappings are maintained through local administrative commands, with an explicit provider tenant and environment. Existing references do not prove clinical access. A mapping conflict requires review; the backfill will not merge customers or change account security. These mappings are not yet exposed as a separate management screen.

Future attribution reporting is intended to connect lead sources, Customer conversion, purchases and lifetime value across API and embedded checkout. Tracking and marketing delivery are not active in this release.


The local order history API and order details addressed by an order ID require a valid portal session and an explicitly owned Customer order. An order ID or matching contact email alone does not grant access. Orders tied to a trusted local checkout are connected when the existing mailbox and clinical-record claim succeeds; API checkout also connects orders for an already claimed lead. Other unassigned historical orders require separate reconciliation; there is no automatic ownership backfill.

# Customers

This first increment introduces **Customers** as the commerce directory. It must be deployed and migrated before these screens appear in a running installation.

Open Customers to create a record with name, contact email, phone and date of birth. Creating a customer does not create a portal login or grant access to a clinical record. Customer contact email can differ from the email used to sign in.

Open a customer to review details and provider references. Choose Edit to update contact information. Provider identifiers are read-only here. Search the directory using the Customer ID or PRX patient number; name/email search is not available in this increment.

In Addresses, choose Add address, select Shipping or Billing, enter the address and optionally mark it as the default for that type. You can edit an existing address. Shipping and billing have separate defaults. Updating the address book does not alter the addresses recorded on previous orders.

For an associated account, authorized staff can use Portal account and security to open the existing session/verification controls. Portal accounts remain separately accessible under the Customers navigation group. Viewing Customer details does not automatically grant access to those security controls.

Staff need Customer view/create/update permissions for the corresponding actions. There are no customer deletion controls in this increment. Existing accounts and PRX customers are not automatically imported yet. Orders, saved payment methods, transactions, refund/void actions and subscriptions will follow in later increments; these screens do not currently process payments.

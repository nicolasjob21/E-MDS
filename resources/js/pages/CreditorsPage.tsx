import AccountHolderPage from '../components/AccountHolderPage';
import { CreditorApi } from '../lib/api';

export default function CreditorsPage() {
    return (
        <AccountHolderPage
            title="Creditors"
            subtitle="Creditors on file, with their account numbers."
            nameLabel="Creditor Name"
            addLabel="Add Creditor"
            noun="creditor(s)"
            api={CreditorApi}
        />
    );
}

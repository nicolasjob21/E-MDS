import AccountHolderPage from '../components/AccountHolderPage';
import { PcgPersonnelApi } from '../lib/api';

export default function PcgPersonnelPage() {
    return (
        <AccountHolderPage
            title="PCG Personnel"
            subtitle="PCG personnel on file, with their account numbers."
            nameLabel="Personnel Name"
            addLabel="Add Personnel"
            noun="personnel record(s)"
            api={PcgPersonnelApi}
        />
    );
}

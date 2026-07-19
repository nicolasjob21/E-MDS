import { Navigate, Route, Routes } from 'react-router-dom';
import { RequireAdmin, RequireAuth } from './components/guards';
import Layout from './components/Layout';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import ChequesPage from './pages/ChequesPage';
import AddRangePage from './pages/AddRangePage';
import UsersPage from './pages/UsersPage';
import LogsPage from './pages/LogsPage';
import UpdateRequestsPage from './pages/UpdateRequestsPage';

export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />

            <Route
                element={
                    <RequireAuth>
                        <Layout />
                    </RequireAuth>
                }
            >
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/cheques" element={<ChequesPage />} />
                <Route
                    path="/admin/add-range"
                    element={
                        <RequireAdmin>
                            <AddRangePage />
                        </RequireAdmin>
                    }
                />
                <Route
                    path="/admin/update-requests"
                    element={
                        <RequireAdmin>
                            <UpdateRequestsPage />
                        </RequireAdmin>
                    }
                />
                <Route
                    path="/admin/users"
                    element={
                        <RequireAdmin>
                            <UsersPage />
                        </RequireAdmin>
                    }
                />
                <Route
                    path="/admin/logs"
                    element={
                        <RequireAdmin>
                            <LogsPage />
                        </RequireAdmin>
                    }
                />
            </Route>

            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
    );
}

import { Routes, Route, Navigate } from "react-router-dom";
import { AppShell } from "@/components/layout/AppShell";
import { ProtectedRoute } from "@/components/layout/ProtectedRoute";

import { LoginPage } from "@/features/auth/LoginPage";
import { ForgotPasswordPage } from "@/features/auth/ForgotPasswordPage";
import { ResetPasswordPage } from "@/features/auth/ResetPasswordPage";
import { DashboardPage } from "@/features/dashboard/DashboardPage";
import { ClientsListPage } from "@/features/clients/ClientsListPage";
import { ClientDetailPage } from "@/features/clients/ClientDetailPage";
import { CampaignsListPage } from "@/features/campaigns/CampaignsListPage";
import { CampaignDetailPage } from "@/features/campaigns/CampaignDetailPage";
import { PersonnelListPage } from "@/features/personnel/PersonnelListPage";
import { AssignmentsListPage } from "@/features/assignments/AssignmentsListPage";
import { ReportsPage } from "@/features/reports/ReportsPage";
import { IssuesListPage } from "@/features/issues/IssuesListPage";
import { AuditLogsPage } from "@/features/audit/AuditLogsPage";
import { TeamsListPage } from "@/features/teams/TeamsListPage";
import { ActivitiesListPage } from "@/features/activities/ActivitiesListPage";
import { SettingsPage } from "@/features/settings/SettingsPage";
import { PlaceholderPage } from "@/features/settings/PlaceholderPage";
import { LocationsListPage } from "@/features/locations/LocationsListPage";

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route index element={<DashboardPage />} />
          <Route path="clients" element={<ClientsListPage />} />
          <Route path="clients/:id" element={<ClientDetailPage />} />
          <Route path="campaigns" element={<CampaignsListPage />} />
          <Route path="campaigns/:id" element={<CampaignDetailPage />} />
          <Route path="personnel" element={<PersonnelListPage />} />
          <Route path="assignments" element={<AssignmentsListPage />} />
          <Route path="activities" element={<ActivitiesListPage />} />
          <Route path="teams" element={<TeamsListPage />} />
          <Route path="locations" element={<LocationsListPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="issues" element={<IssuesListPage />} />
          <Route path="audit-logs" element={<AuditLogsPage />} />
          <Route path="settings" element={<SettingsPage />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
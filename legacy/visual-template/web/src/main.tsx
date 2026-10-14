import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { QueryClientProvider } from "@tanstack/react-query";
import { queryClient } from "@/lib/queryClient";
import { ToastProvider } from "@/hooks/useToast";
import { ScopeProvider } from "@/hooks/useScope";
import { Toaster } from "@/components/ui/Toaster";
import App from "./App";
import "./index.css";

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <ScopeProvider>
          <BrowserRouter>
            <App />
          </BrowserRouter>
        </ScopeProvider>
        <Toaster />
      </ToastProvider>
    </QueryClientProvider>
  </React.StrictMode>
);
import { FormEvent, useState } from "react";
import { Link } from "react-router-dom";
import { supabase } from "@/lib/supabase";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";

export function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true); setError(null); setMessage(null);
    const { error } = await supabase.auth.resetPasswordForEmail(email, {
      redirectTo: `${window.location.origin}/reset-password`,
    });
    setLoading(false);
    if (error) setError(error.message);
    else setMessage("If that email exists, a reset link has been sent.");
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h1 className="mb-1 text-lg font-semibold text-slate-900">Reset password</h1>
        <p className="mb-5 text-sm text-slate-500">Enter your email to receive a reset link.</p>
        <form onSubmit={onSubmit} className="space-y-4">
          <Input label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          {error && <p className="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>}
          {message && <p className="rounded border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-700">{message}</p>}
          <Button type="submit" loading={loading} className="w-full">Send reset link</Button>
        </form>
        <div className="mt-4 text-center text-xs">
          <Link to="/login" className="text-slate-500 hover:text-slate-700">Back to sign in</Link>
        </div>
      </div>
    </div>
  );
}
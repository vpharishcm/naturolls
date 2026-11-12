import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Field, inputClass } from '../components/ui';
import { ApiError } from '../lib/api';
import { useApp } from '../stores/app';

const schema = z.object({
  username: z.string().min(1, 'Enter your username'),
  password: z.string().min(1, 'Enter your password'),
});

export default function Login() {
  const login = useApp((s) => s.login);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema) });

  const submit = handleSubmit(async (v) => {
    setError(null);
    try {
      await login(v.username, v.password);
    } catch (e) {
      const err = e as ApiError;
      setError(err.isNetwork ? 'Cannot reach the server. Signing in needs a connection the first time.' : err.message);
    }
  });

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <form onSubmit={submit} className="flex w-full max-w-sm flex-col gap-4">
        <div>
          <div className="text-[15px] font-extrabold text-muted-soft">Billing and stock</div>
          <h1 className="mt-0.5 text-[40px] font-black leading-tight text-berry">Naturolls</h1>
        </div>
        {error && <div role="alert" className="rounded-2xl bg-rust-soft px-4 py-3 text-[17px] font-extrabold text-rust">{error}</div>}
        <Field label="Username" error={formState.errors.username?.message}>
          <input className={inputClass} autoCapitalize="none" autoComplete="username" {...register('username')} />
        </Field>
        <Field label="Password" error={formState.errors.password?.message}>
          <input className={inputClass} type="password" autoComplete="current-password" {...register('password')} />
        </Field>
        <button type="submit" disabled={formState.isSubmitting} className="h-[68px] rounded-[20px] bg-berry text-[21px] font-black text-white disabled:bg-line-strong">
          {formState.isSubmitting ? 'SIGNING IN…' : 'SIGN IN'}
        </button>
      </form>
    </div>
  );
}

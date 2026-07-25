import { Link } from '@inertiajs/react';

type BrandProps = {
  compact?: boolean;
  className?: string;
};

export function Brand({ compact = false, className = '' }: BrandProps) {
  return <Link href="/" aria-label="IpamFerry home" className={`inline-flex items-center gap-2.5 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950 ${className}`.trim()}>
    <img src="/brand/ipamferry-shield-512.png" width="36" height="36" alt="" aria-hidden="true" className="h-9 w-9 shrink-0" />
    {!compact && <span className="text-lg font-bold tracking-tight text-slate-100">Ipam<span className="text-sky-400">Ferry</span></span>}
  </Link>;
}

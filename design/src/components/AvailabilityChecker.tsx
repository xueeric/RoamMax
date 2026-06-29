import React, { useState } from 'react';
import { Search, Loader2, Signal, Wifi, Activity, Compass, Info } from 'lucide-react';
import { checkAvailabilityMock, CoverageReport } from '../data/rentalData';

interface AvailabilityCheckerProps {
  onSelectRecommendedKit: (kitId: string) => void;
}

export default function AvailabilityChecker({ onSelectRecommendedKit }: AvailabilityCheckerProps) {
  const [query, setQuery] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [report, setReport] = useState<CoverageReport | null>(null);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (!query.trim()) return;

    setIsSearching(true);
    // Simulate high-performance LEO tracking check
    setTimeout(() => {
      const result = checkAvailabilityMock(query);
      setReport(result);
      setIsSearching(false);
    }, 600);
  };

  const getStatusColor = (status: CoverageReport['status']) => {
    switch (status) {
      case 'excellent': return 'text-emerald-800 bg-emerald-50/70 border-emerald-100';
      case 'good': return 'text-blue-800 bg-blue-50/70 border-blue-100';
      case 'average': return 'text-amber-800 bg-amber-50/70 border-amber-100';
      case 'unsupported': return 'text-rose-800 bg-rose-50/70 border-rose-100';
    }
  };

  return (
    <div className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm relative overflow-hidden" id="availability-card">
      <div className="mb-4">
        <div className="flex items-center space-x-2">
          <span className="flex h-2 w-2 rounded-full bg-black animate-pulse" />
          <span className="font-mono text-[10px] font-bold uppercase tracking-widest text-gray-400">01. Service Verification</span>
        </div>
        <h3 className="font-display text-lg font-bold text-gray-900 mt-2">Coverage &amp; Signal Checker</h3>
        <p className="text-xs text-gray-500 mt-1">
          Simulate a real-time low earth orbit altitude sweep for your deployment sector.
        </p>
      </div>

      <form onSubmit={handleSearch} className="relative mt-4">
        <div className="relative flex items-center">
          <Search className="absolute left-3.5 h-4 w-4 text-gray-400" />
          <input
            type="text"
            required
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Enter ZIP code, city, or coordinate region..."
            className="w-full rounded-xl border border-gray-200 bg-gray-50 py-3.5 pl-10 pr-24 text-sm text-gray-900 placeholder-gray-400 outline-none transition focus:border-black focus:ring-1 focus:ring-black"
            id="availability-input"
          />
          <button
            type="submit"
            disabled={isSearching}
            className="absolute right-1.5 rounded-lg bg-black px-4 py-2 text-xs font-semibold text-white transition hover:bg-gray-800 disabled:opacity-50 flex items-center space-x-1"
            id="availability-submit"
          >
            {isSearching ? (
              <>
                <Loader2 className="h-3 w-3 animate-spin" />
                <span>Scanning</span>
              </>
            ) : (
              <span>Check</span>
            )}
          </button>
        </div>
        <p className="mt-2.5 text-[11px] text-gray-400 font-mono">
          Try inputting <span className="text-blue-600 underline cursor-pointer hover:text-blue-800" onClick={() => setQuery('Yosemite Valley')}>Yosemite Mountain</span>, <span className="text-blue-600 underline cursor-pointer hover:text-blue-800" onClick={() => setQuery('90210')}>90210</span>, or <span className="text-blue-600 underline cursor-pointer hover:text-blue-800" onClick={() => setQuery('Moab Desert')}>Moab Desert</span>.
        </p>
      </form>

      {/* Output Report */}
      {report && (
        <div className="mt-5 border-t border-gray-100 pt-5 animate-fadeIn" id="availability-report">
          <div className={`rounded-xl border p-4 ${getStatusColor(report.status)} mb-4`}>
            <div className="flex items-start space-x-3">
              <Signal className="h-5 w-5 shrink-0 mt-0.5 text-gray-700" />
              <div>
                <div className="flex items-center space-x-2">
                  <span className="font-bold text-xs font-mono uppercase tracking-wider">
                    {report.status} Orbital Sync
                  </span>
                  <span className="inline-block h-1.5 w-1.5 rounded-full bg-current" />
                  <span className="text-xs font-mono">{report.latitudeEstimate}</span>
                </div>
                <p className="text-xs text-gray-700 mt-1.5 font-sans leading-relaxed">{report.message}</p>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3.5">
            {/* Satellite density */}
            <div className="rounded-xl border border-gray-200 bg-gray-50/50 p-3.5">
              <div className="flex items-center justify-between text-[11px] font-mono text-gray-400 font-bold">
                <span>Constellation Density</span>
                <Compass className="h-3.5 w-3.5 text-gray-500" />
              </div>
              <div className="flex items-baseline space-x-1.5 mt-1.5">
                <span className="text-xl font-bold font-mono tracking-tight text-gray-900">{report.satelliteDensity}%</span>
                <span className="text-[10px] text-gray-400">optimal</span>
              </div>
              {/* Progress bar container */}
              <div className="w-full bg-gray-200 h-1.5 rounded-full mt-2.5 overflow-hidden">
                <div 
                  className="bg-black h-1.5 rounded-full transition-all duration-500"
                  style={{ width: `${report.satelliteDensity}%` }}
                />
              </div>
            </div>

            {/* Simulated ping */}
            <div className="rounded-xl border border-gray-200 bg-gray-50/50 p-3.5">
              <div className="flex items-center justify-between text-[11px] font-mono text-gray-400 font-bold">
                <span>Sector Latency</span>
                <Activity className="h-3.5 w-3.5 text-gray-500" />
              </div>
              <div className="flex items-baseline space-x-1.5 mt-1.5">
                <span className="text-xl font-bold font-mono tracking-tight text-gray-900">{report.averagePing}ms</span>
                <span className="text-[10px] text-emerald-600">(Stable)</span>
              </div>
              <p className="text-[9px] text-gray-400 mt-2.5 font-mono truncate">Fast ping LEO alignment</p>
            </div>
          </div>

          {/* Quick actions Based on report recommendation */}
          <div className="mt-4 rounded-xl bg-gray-50 border border-gray-100 p-4 flex items-center justify-between">
            <div className="flex items-center space-x-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-black border border-gray-200">
                <Wifi className="h-4.5 w-4.5" />
              </div>
              <div>
                <p className="text-[9px] font-mono font-bold uppercase text-gray-400 leading-shrink">Matched Device Suite</p>
                <p className="text-xs font-semibold text-gray-900">
                  {report.recommendedKitId === 'standard-kit' ? 'Starlink Standard (V4)' : 
                   report.recommendedKitId === 'flat-high-performance' ? 'Flat In-Motion V3' : 'Enterprise High Performance'}
                </p>
              </div>
            </div>
            <button
              onClick={() => onSelectRecommendedKit(report.recommendedKitId)}
              className="rounded-lg bg-black text-white border border-transparent px-3 py-1.5 text-xs font-semibold hover:bg-gray-800 transition cursor-pointer"
              id="select-recommended-kit"
            >
              Select Kit
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

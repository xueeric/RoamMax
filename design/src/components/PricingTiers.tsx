import React, { useState } from 'react';
import { Check, HardDrive, ArrowRight } from 'lucide-react';
import { RENTAL_PLANS } from '../data/rentalData';

interface PricingTiersProps {
  selectedKitId: string;
  onSelectKit: (kitId: string) => void;
  onScrollToBooking: () => void;
}

export default function PricingTiers({ selectedKitId, onSelectKit, onScrollToBooking }: PricingTiersProps) {
  const [activePlanId, setActivePlanId] = useState<string | null>(null);

  const handleSelectPlan = (kitId: string) => {
    onSelectKit(kitId);
    onScrollToBooking();
  };

  return (
    <div className="space-y-10">
      {/* Title block */}
      <div className="text-center max-w-2xl mx-auto px-4">
        <div className="flex justify-center items-center space-x-1.5 mb-2.5">
          <span className="h-1 w-6 bg-black rounded" />
          <span className="font-mono text-xs font-bold uppercase tracking-widest text-gray-400">02. Selected Tier Hardware</span>
          <span className="h-1 w-6 bg-black rounded" />
        </div>
        <h2 className="font-display text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl" id="pricing-heading">
          Flexible Setup Options &amp; Flat Pricing
        </h2>
        <p className="mt-3 text-sm text-gray-500">
          No activation fees, zero contracts, unlimited satellite high-speed data. Every complete antenna travels in customized shockproof rugged flight-cases.
        </p>
      </div>

      {/* Grid List */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" id="pricing-grid">
        {RENTAL_PLANS.map((plan) => {
          const isSelected = selectedKitId === plan.id;
          const isShowingDetails = activePlanId === plan.id;

          return (
            <div
              key={plan.id}
              className={`relative flex flex-col rounded-2xl border bg-white transition-all duration-300 ${
                isSelected 
                  ? 'border-black shadow-lg ring-1 ring-black' 
                  : 'border-gray-150 bg-white hover:border-gray-300'
              }`}
              id={`pricing-card-${plan.id}`}
            >
              {plan.badge && (
                <div className="absolute -top-3 right-5">
                  <span className="inline-flex rounded-full bg-black text-white px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide">
                    {plan.badge}
                  </span>
                </div>
              )}

              {/* Head Stats */}
              <div className="p-6 sm:p-7 flex-1">
                <span className="font-mono text-[9px] font-bold uppercase tracking-widest text-gray-400">STARLINK ANTENNA SYSTEM</span>
                <h3 className="font-display text-xl font-bold text-gray-900 mt-1">{plan.name}</h3>
                <p className="mt-3 text-xs text-gray-500 leading-relaxed min-h-[38px]">
                  {plan.tagline}
                </p>

                {/* Pricing Block */}
                <div className="mt-5 rounded-xl bg-gray-50 p-4 border border-gray-100">
                  <div className="grid grid-cols-2 gap-2 divide-x divide-gray-200">
                    <div>
                      <span className="block font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold">Weekly Flat</span>
                      <div className="flex items-baseline space-x-0.5 mt-0.5">
                        <span className="text-xl font-bold font-mono text-gray-900">${plan.kitCost.rentalPerWeek}</span>
                        <span className="text-[10px] text-gray-400">/wk</span>
                      </div>
                    </div>
                    <div className="pl-4">
                      <span className="block font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold">Monthly Flat</span>
                      <div className="flex items-baseline space-x-0.5 mt-0.5">
                        <span className="text-xl font-bold font-mono text-blue-600">${plan.kitCost.rentalPerMonth}</span>
                        <span className="text-[10px] text-gray-400">/mo</span>
                      </div>
                    </div>
                  </div>
                  <div className="mt-3 pt-3 border-t border-gray-200 flex justify-between items-center text-[10px] font-mono text-gray-500">
                    <span>Refundable Deposit:</span>
                    <span className="text-gray-900 font-bold">${plan.kitCost.deposit}</span>
                  </div>
                </div>

                {/* Specs Box */}
                <div className="mt-5 space-y-2 font-mono text-[11px] border-y border-gray-100 py-3.5">
                  <div className="flex justify-between items-center">
                    <span className="text-gray-400">Download Speeds</span>
                    <span className="text-gray-900 font-semibold">{plan.techSpec.downloadSpeed}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-gray-400">Typical Latency</span>
                    <span className="text-emerald-700 font-semibold">{plan.techSpec.latency}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-gray-400">Survival Wind</span>
                    <span className="text-gray-600">{plan.techSpec.windRating}</span>
                  </div>
                </div>

                {/* Features list */}
                <ul className="mt-5 space-y-2.5">
                  {plan.features.map((feature, idx) => (
                    <li key={idx} className="flex items-start text-xs text-gray-600 leading-snug">
                      <Check className="h-4 w-4 shrink-0 text-black mr-2 mt-0.5" />
                      <span>{feature}</span>
                    </li>
                  ))}
                </ul>
              </div>

              {/* Action Buttons */}
              <div className="p-6 bg-gray-50/50 border-t border-gray-100 rounded-b-2xl">
                <div className="flex flex-col space-y-2">
                  <button
                    onClick={() => handleSelectPlan(plan.id)}
                    className={`w-full rounded-xl py-3 text-xs font-bold tracking-wide transition active:scale-95 text-center flex items-center justify-center space-x-1 cursor-pointer ${
                      isSelected
                        ? 'bg-black text-white hover:bg-gray-800 shadow-sm'
                        : 'border border-gray-200 text-gray-800 bg-white hover:bg-gray-50 hover:text-black hover:border-gray-300'
                    }`}
                    id={`btn-select-plan-${plan.id}`}
                  >
                    <span>{isSelected ? 'Selected' : 'Choose This Kit'}</span>
                    <ArrowRight className="h-3.5 w-3.5" />
                  </button>

                  <button
                    onClick={() => setActivePlanId(isShowingDetails ? null : plan.id)}
                    className="w-full text-center text-[10px] font-mono text-gray-400 hover:text-gray-700 transition py-1 flex items-center justify-center space-x-1"
                    id={`btn-toggle-specs-${plan.id}`}
                  >
                    <span>{isShowingDetails ? 'Close Contents List' : 'Inspect Package Contents'}</span>
                    <span>{isShowingDetails ? '↑' : '↓'}</span>
                  </button>
                </div>

                {/* Description expansion details */}
                {isShowingDetails && (
                  <div className="mt-4 border-t border-gray-200 pt-4 text-xs space-y-3.5 text-gray-600 animate-fadeIn" id={`specs-${plan.id}`}>
                    <div>
                      <p className="font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold mb-1.5 flex items-center">
                        <HardDrive className="h-3 w-3 text-black mr-1" /> Flight-Case Contents
                      </p>
                      <ul className="list-disc pl-4 space-y-1 text-[11px] text-gray-500">
                        {plan.equipmentIncluded.map((eq, i) => (
                          <li key={i}>{eq}</li>
                        ))}
                      </ul>
                    </div>
                    <div className="bg-white rounded-lg p-2.5 text-[11px] border border-gray-100">
                      <p className="font-mono text-[9px] uppercase text-gray-400 font-bold mb-1">Telemetry Draw:</p>
                      <p className="text-[10px] text-gray-700 font-mono">{plan.techSpec.powerConsumption}</p>
                    </div>
                    <div>
                      <p className="font-mono text-[9px] uppercase text-gray-400 font-bold mb-1">Target Scenario Use:</p>
                      <p className="text-[11px] text-gray-500 leading-relaxed font-sans">{plan.idealFor}</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

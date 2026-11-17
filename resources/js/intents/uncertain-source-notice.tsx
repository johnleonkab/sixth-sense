/** The line on an approval card when the draft rests on an uncertain link. */
export default function UncertainSourceNotice({ notice }: { notice: string }) {
    return <p className="text-[13px] text-ink">{notice}</p>;
}

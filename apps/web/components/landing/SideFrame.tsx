import Image from "next/image";

export function SideFrame({
  src,
  alt = "",
  flip = false,
  children,
}: {
  src: string;
  alt?: string;
  flip?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section className="reveal grid items-center gap-10 md:grid-cols-2">
      <figure className={`holo ${flip ? "md:order-2" : ""}`}>
        <Image
          src={src}
          alt={alt}
          width={1600}
          height={900}
          className="blend-photo h-auto w-full border-0 shadow-none"
        />
        <span className="holo-sheen" aria-hidden="true" />
      </figure>
      <div className={flip ? "md:order-1" : ""}>{children}</div>
    </section>
  );
}


    (() => {
        const createModal = document.getElementById("createModal");
        const detailModal = document.getElementById("detailModal");

        const openCreateModalButton =
            document.getElementById("openCreateModal");

        const titleInput = document.getElementById("title");

        const detailModalTitle =
            document.getElementById("detailModalTitle");

        const detailModalMeta =
            document.getElementById("detailModalMeta");

        const modalStatus =
            document.getElementById("modalStatus");

        const modalStatusBadge =
            document.getElementById("modalStatusBadge");

        const modalShortUrl =
            document.getElementById("modalShortUrl");

        const modalDestinationUrl =
            document.getElementById("modalDestinationUrl");

        const modalDetailLink =
            document.getElementById("modalDetailLink");


        // 新規登録モーダルを開く
        openCreateModalButton.addEventListener("click", () => {
            createModal.showModal();

            requestAnimationFrame(() => {
                titleInput.focus();
            });
        });


        // 登録済みURLの詳細モーダルを開く
        const openDetailModal = (row) => {
            const {
                title,
                status,
                createdAt,
                shortUrl,
                destinationUrl,
                detailUrl
            } = row.dataset;

            detailModalTitle.textContent =
                title || "URL登録内容";

            detailModalMeta.textContent =
                createdAt
                    ? `登録日：${createdAt}`
                    : "";

            modalStatus.textContent =
                status || "";

            modalShortUrl.textContent =
                shortUrl || "";

            modalShortUrl.href =
                shortUrl || "#";

            modalDestinationUrl.textContent =
                destinationUrl || "";

            modalDestinationUrl.href =
                destinationUrl || "#";

            modalDetailLink.href =
                detailUrl || "#";

            const isActive = status === "有効";

            modalStatusBadge.classList.toggle(
                "status-badge--active",
                isActive
            );

            modalStatusBadge.classList.toggle(
                "status-badge--inactive",
                !isActive
            );

            detailModal.showModal();
        };


        document.querySelectorAll(".url-row").forEach((row) => {
            row.addEventListener("click", () => {
                openDetailModal(row);
            });

            row.addEventListener("keydown", (event) => {
                if (
                    event.key !== "Enter" &&
                    event.key !== " "
                ) {
                    return;
                }

                event.preventDefault();
                openDetailModal(row);
            });
        });

        // モーダルを閉じる
        document
            .querySelectorAll("[data-close-modal]")
            .forEach((button) => {
                button.addEventListener("click", () => {
                    button.closest("dialog").close();
                });
            });

        // モーダル外側のクリックで閉じる
        document.querySelectorAll("dialog").forEach((dialog) => {
            dialog.addEventListener("click", (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });
    })();
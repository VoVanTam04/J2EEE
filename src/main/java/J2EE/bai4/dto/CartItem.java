package J2EE.bai4.dto;

import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.NoArgsConstructor;

@Data
@NoArgsConstructor
@AllArgsConstructor
public class CartItem {
    private int productId;
    private String name;
    private Long price;
    private String image;
    private int quantity;

    public Long getAmount() {
        return price * quantity;
    }
}
